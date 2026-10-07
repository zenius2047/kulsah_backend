<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WalletService
{
    private const USD_SCALE = 4;

    public function getOrCreateUserWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'account_name' => $user->name ?: $user->username ?: 'User Wallet',
                'base_currency' => config('wallet.base_currency', 'GHS'),
                'status' => 'active',
                'available_balance_usd' => 0,
                'pending_balance_usd' => 0,
                'held_balance_usd' => 0,
            ]
        );
    }

    public function getOrCreateSystemWallet(string $accountKey, string $accountName): Wallet
    {
        return Wallet::firstOrCreate(
            ['account_key' => $accountKey],
            [
                'account_name' => $accountName,
                'base_currency' => config('wallet.base_currency', 'GHS'),
                'status' => 'active',
                'available_balance_usd' => 0,
                'pending_balance_usd' => 0,
                'held_balance_usd' => 0,
            ]
        );
    }

    public function getWalletSummary(Wallet $wallet): array
    {
        $wallet->loadMissing('user');

        return [
            'wallet' => $wallet,
            'balances' => [
                'currency' => $wallet->base_currency,
                'available' => $wallet->available_balance_usd,
                'pending' => $wallet->pending_balance_usd,
                'held' => $wallet->held_balance_usd,
                'total' => $this->toDecimal(
                    ((float) $wallet->available_balance_usd) +
                    ((float) $wallet->pending_balance_usd) +
                    ((float) $wallet->held_balance_usd)
                ),
            ],
        ];
    }

    public function transferBetweenWallets(
        Wallet $fromWallet,
        Wallet $toWallet,
        string|int|float $amountUsd,
        string $type,
        ?string $description = null,
        array $metadata = [],
        string $fromBucket = 'available',
        string $toBucket = 'pending',
        ?User $actor = null,
        bool $allowNegativeSourceBalance = false
    ): WalletTransaction {
        return DB::transaction(function () use (
            $fromWallet,
            $toWallet,
            $amountUsd,
            $type,
            $description,
            $metadata,
            $fromBucket,
            $toBucket,
            $actor,
            $allowNegativeSourceBalance
        ) {
            $amount = $this->normalizeAmount($amountUsd);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount_usd' => 'The transfer amount must be greater than zero.',
                ]);
            }

            $this->assertWalletCanDebit($fromWallet, $fromBucket, $amount, $allowNegativeSourceBalance);

            $transaction = WalletTransaction::create([
                'reference' => (string) Str::uuid(),
                'type' => $type,
                'status' => 'completed',
                'user_id' => $fromWallet->user_id ?? $toWallet->user_id,
                'counterparty_wallet_id' => $toWallet->id,
                'usd_amount' => $amount,
                'platform_fee_usd' => 0,
                'processor_fee_usd' => 0,
                'net_usd_amount' => $amount,
                'description' => $description,
                'metadata' => $metadata,
                'performed_by_user_id' => $actor?->id,
                'processed_at' => now(),
            ]);

            $this->recordEntry($transaction, $fromWallet, 'debit', $fromBucket, $amount, $description, $metadata);
            $this->recordEntry($transaction, $toWallet, 'credit', $toBucket, $amount, $description, $metadata);

            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.walletTransaction', 'wallet', 'counterpartyWallet']);
        });
    }

    public function recordPayment(
        User $payer,
        User $creator,
        string|int|float $localAmount,
        string $localCurrency,
        string|int|float $fxRateUsed,
        string|int|float $platformFeeUsd = 0,
        string|int|float $processorFeeUsd = 0,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        bool $useTransaction = true
    ): WalletTransaction {
        $action = function () use (
            $payer,
            $creator,
            $localAmount,
            $localCurrency,
            $fxRateUsed,
            $platformFeeUsd,
            $processorFeeUsd,
            $description,
            $metadata,
            $actor
        ) {
            $localAmount = $this->normalizeAmount($localAmount);
            $fxRateUsed = $this->normalizeAmount($fxRateUsed, 6);
            $platformFeeUsd = $this->normalizeAmount($platformFeeUsd);
            $processorFeeUsd = $this->normalizeAmount($processorFeeUsd);

            if ($localAmount <= 0 || $fxRateUsed <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment amount and FX rate must be greater than zero.',
                ]);
            }

            $usdAmount = $this->toDecimal($localAmount / $fxRateUsed);
            $netUsd = $this->toDecimal($usdAmount - $platformFeeUsd - $processorFeeUsd);
            $ruleCalculation = null;
            $chargedUsd = $usdAmount;
            $chargedLocal = $localAmount;
            $platformDebitUsd = 0.0;
            $taxFeeUsd = 0.0;
            $platformCreditUsd = $platformFeeUsd;
            $gatewayCreditUsd = $processorFeeUsd;
            $remainingRecipient = 'creator';

            if (($metadata['revenue_source'] ?? null) && strtoupper($localCurrency) === 'GHS') {
                $ruleCalculation = app(RevenueRuleCalculator::class)->calculate(
                    (string) $metadata['revenue_source'], $localAmount, 'GHS',
                    ['creator_id' => $creator->id, 'country' => $creator->country]
                );

                if ($ruleCalculation['deductions'] !== []) {
                    $sumDeductions = fn (callable $filter): float => array_sum(array_map(
                        fn (array $deduction) => $filter($deduction) ? (float) $deduction['amount'] : 0,
                        $ruleCalculation['deductions']
                    ));
                    $creatorPaid = $sumDeductions(fn ($d) => in_array($d['payer'], ['creator', 'organizer'], true));
                    $creatorReceived = $sumDeductions(fn ($d) => in_array($d['recipient'], ['creator', 'organizer'], true));
                    if (count($ruleCalculation['remainingRecipients']) > 1) {
                        throw ValidationException::withMessages(['revenue_rules' => 'Matching revenue rules disagree about who receives the remaining amount.']);
                    }
                    $remainingRecipient = $ruleCalculation['remainingRecipient'] ?? 'creator';
                    $customerPaid = $sumDeductions(fn ($d) => $d['payer'] === 'customer');
                    $platformDebitUsd = $sumDeductions(fn ($d) => $d['payer'] === 'platform' && $d['recipient'] !== 'platform') / $fxRateUsed;
                    $platformCreditUsd = $sumDeductions(fn ($d) => $d['recipient'] === 'platform' && $d['payer'] !== 'platform') / $fxRateUsed;
                    $gatewayCreditUsd = $sumDeductions(fn ($d) => $d['recipient'] === 'gateway') / $fxRateUsed;
                    $taxFeeUsd = $sumDeductions(fn ($d) => $d['recipient'] === 'tax_authority') / $fxRateUsed;
                    $chargedLocal = $this->normalizeAmount($localAmount + $customerPaid);
                    $chargedUsd = $this->toDecimal($usdAmount + ($customerPaid / $fxRateUsed));
                    $netUsd = $this->toDecimal($usdAmount - ($creatorPaid / $fxRateUsed) + ($creatorReceived / $fxRateUsed));
                    $platformFeeUsd = $this->toDecimal($platformCreditUsd);
                    $processorFeeUsd = $this->toDecimal($gatewayCreditUsd + $taxFeeUsd);
                    if ($netUsd < 0) {
                        throw ValidationException::withMessages(['fees' => 'Creator-paid revenue deductions cannot exceed the transaction amount.']);
                    }
                    $metadata = array_merge($metadata, [
                        'gross_amount' => $localAmount,
                        'total_charged' => $chargedLocal,
                        'revenue_rule_calculation' => $ruleCalculation,
                    ]);
                }
            }

            if ($netUsd < 0) {
                throw ValidationException::withMessages([
                    'fees' => 'Fees cannot exceed the converted USD amount.',
                ]);
            }

            $payerWallet = $this->getOrCreateUserWallet($payer);
            $creatorWallet = $this->getOrCreateUserWallet($creator);
            $platformWallet = $this->getOrCreateSystemWallet('platform', 'Kulsah Platform Wallet');
            $processorWallet = $this->getOrCreateSystemWallet('processor', 'Kulsah Processor Wallet');

            $this->assertWalletCanDebit($payerWallet, 'available', $chargedUsd);
            if ($platformDebitUsd > 0) $this->assertWalletCanDebit($platformWallet, 'available', $platformDebitUsd);

            $remainingWallet = match ($remainingRecipient) {
                'platform' => $platformWallet,
                'customer' => $payerWallet,
                default => $creatorWallet,
            };
            $transaction = WalletTransaction::create([
                'reference' => (string) Str::uuid(),
                'type' => 'payment',
                'status' => 'completed',
                'user_id' => $payer->id,
                'counterparty_wallet_id' => $remainingWallet->id,
                'local_currency' => strtoupper($localCurrency),
                'local_amount' => $chargedLocal,
                'usd_amount' => $chargedUsd,
                'fx_rate_used' => $fxRateUsed,
                'platform_fee_usd' => $platformFeeUsd,
                'processor_fee_usd' => $processorFeeUsd,
                'net_usd_amount' => $netUsd,
                'description' => $description,
                'metadata' => $metadata,
                'performed_by_user_id' => $actor?->id ?? $payer->id,
                'processed_at' => now(),
            ]);

            $this->recordEntry($transaction, $payerWallet, 'debit', 'available', $chargedUsd, $description, $metadata);
            $payoutBucket = in_array($remainingRecipient, ['creator', 'organizer'], true) ? 'pending' : 'available';
            $this->recordEntry($transaction, $remainingWallet, 'credit', $payoutBucket, $netUsd, $description, $metadata,
                $payoutBucket === 'pending' ? now()->addDays($this->holdingPeriodDays()) : null);

            if ($platformFeeUsd > 0) {
                $this->recordEntry($transaction, $platformWallet, 'credit', 'available', $platformFeeUsd, 'Platform fee', $metadata);
            }

            if ($platformDebitUsd > 0) {
                $this->recordEntry($transaction, $platformWallet, 'debit', 'available', $platformDebitUsd, 'Platform-paid deduction', $metadata);
            }

            if ($processorFeeUsd > 0) {
                if ($gatewayCreditUsd > 0) $this->recordEntry($transaction, $processorWallet, 'credit', 'available', $gatewayCreditUsd, 'Payment gateway fee', $metadata);
                if ($taxFeeUsd > 0) {
                    $taxWallet = $this->getOrCreateSystemWallet('tax_authority', 'Tax Authority Payable');
                    $this->recordEntry($transaction, $taxWallet, 'credit', 'available', $taxFeeUsd, 'Tax deduction', $metadata);
                }
            }

            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.walletTransaction', 'wallet', 'counterpartyWallet']);
        };

        return $useTransaction ? DB::transaction($action) : $action();
    }

    public function moveHeldToAvailable(
        Wallet $wallet,
        string|int|float $amountUsd,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null
    ): WalletTransaction {
        return $this->moveWithinWallet($wallet, 'held', 'available', $amountUsd, 'release_hold', $description, $metadata, $actor);
    }

    public function movePendingToAvailable(
        Wallet $wallet,
        string|int|float $amountUsd,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null
    ): WalletTransaction {
        return $this->moveWithinWallet($wallet, 'pending', 'available', $amountUsd, 'release_pending', $description, $metadata, $actor);
    }

    public function moveAvailableToHeld(
        Wallet $wallet,
        string|int|float $amountUsd,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null
    ): WalletTransaction {
        return $this->moveWithinWallet($wallet, 'available', 'held', $amountUsd, 'hold_funds', $description, $metadata, $actor);
    }

    public function reverseWalletTransaction(
        WalletTransaction $sourceTransaction,
        ?string $reason = null,
        ?User $actor = null
    ): WalletTransaction {
        return DB::transaction(function () use ($sourceTransaction, $reason, $actor) {
            $sourceTransaction->loadMissing(['entries.wallet', 'wallet', 'counterpartyWallet']);

            $reversalReference = "refund-reversal-{$sourceTransaction->id}";

            $existing = WalletTransaction::query()
                ->where('reference', $reversalReference)
                ->first();

            if ($existing) {
                return $existing->load(['entries.wallet', 'entries.walletTransaction', 'wallet', 'counterpartyWallet']);
            }

            if ($sourceTransaction->entries->isEmpty()) {
                throw new InvalidArgumentException('The source transaction has no ledger entries to reverse.');
            }

            $reversal = WalletTransaction::create([
                'reference' => $reversalReference,
                'type' => 'refund_reversal',
                'status' => 'completed',
                'user_id' => $sourceTransaction->user_id,
                'counterparty_wallet_id' => $sourceTransaction->counterparty_wallet_id,
                'usd_amount' => $sourceTransaction->usd_amount,
                'net_usd_amount' => $sourceTransaction->net_usd_amount,
                'description' => $reason ?? "Reversal of transaction {$sourceTransaction->reference}",
                'metadata' => array_merge($sourceTransaction->metadata ?? [], [
                    'original_transaction_id' => $sourceTransaction->id,
                    'original_reference' => $sourceTransaction->reference,
                    'reversal_reason' => $reason,
                ]),
                'performed_by_user_id' => $actor?->id,
                'processed_at' => now(),
            ]);

            foreach ($sourceTransaction->entries as $entry) {
                $oppositeType = $entry->entry_type === 'credit' ? 'debit' : 'credit';

                $this->recordEntry(
                    $reversal,
                    $entry->wallet,
                    $oppositeType,
                    $entry->balance_bucket,
                    $entry->amount_usd,
                    $reason ?? 'Refund reversal',
                    [
                        'original_transaction_id' => $sourceTransaction->id,
                        'original_entry_id' => $entry->id,
                    ]
                );
            }

            $this->assertTransactionBalanced($reversal);

            return $reversal->load(['entries.wallet', 'entries.walletTransaction', 'wallet', 'counterpartyWallet']);
        });
    }

    private function moveWithinWallet(
        Wallet $wallet,
        string $fromBucket,
        string $toBucket,
        string|int|float $amountUsd,
        string $type,
        ?string $description,
        array $metadata,
        ?User $actor
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $fromBucket, $toBucket, $amountUsd, $type, $description, $metadata, $actor) {
            $amount = $this->normalizeAmount($amountUsd);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount_usd' => 'The amount must be greater than zero.',
                ]);
            }

            $this->assertWalletCanDebit($wallet, $fromBucket, $amount);

            $transaction = WalletTransaction::create([
                'reference' => (string) Str::uuid(),
                'type' => $type,
                'status' => 'completed',
                'user_id' => $wallet->user_id,
                'usd_amount' => $amount,
                'net_usd_amount' => $amount,
                'description' => $description,
                'metadata' => $metadata,
                'performed_by_user_id' => $actor?->id ?? $wallet->user_id,
                'processed_at' => now(),
            ]);

            $this->recordEntry($transaction, $wallet, 'debit', $fromBucket, $amount, $description, $metadata);
            $this->recordEntry($transaction, $wallet, 'credit', $toBucket, $amount, $description, $metadata);

            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.walletTransaction', 'wallet']);
        });
    }

    /**
     * Settle matured pending credits into available balance.
     *
     * @return array{wallets:int, entries:int, amount_usd:float}
     */
    public function settlePendingFunds(?Carbon $cutoff = null): array
    {
        $cutoff = $cutoff ?? now()->subDays($this->holdingPeriodDays());

        $eligibleEntries = WalletLedgerEntry::query()
            ->where('entry_type', 'credit')
            ->where('balance_bucket', 'pending')
            ->whereNull('settled_at')
            ->whereNotNull('settlement_available_at')
            ->where('settlement_available_at', '<=', $cutoff->copy()->endOfMinute())
            ->orderBy('wallet_id')
            ->orderBy('id')
            ->get();

        if ($eligibleEntries->isEmpty()) {
            return ['wallets' => 0, 'entries' => 0, 'amount_usd' => 0.0];
        }

        $walletCount = 0;
        $entryCount = 0;
        $settledAmount = 0.0;

        foreach ($eligibleEntries->groupBy('wallet_id') as $walletId => $entries) {
            DB::transaction(function () use ($walletId, $entries, $cutoff, &$walletCount, &$entryCount, &$settledAmount) {
                $wallet = Wallet::query()->lockForUpdate()->find($walletId);

                if (! $wallet) {
                    return;
                }

                $freshEntries = WalletLedgerEntry::query()
                    ->whereIn('id', $entries->pluck('id'))
                    ->whereNull('settled_at')
                    ->where('settlement_available_at', '<=', $cutoff->copy()->endOfMinute())
                    ->lockForUpdate()
                    ->get();

                if ($freshEntries->isEmpty()) {
                    return;
                }

                $amount = $this->toDecimal((float) $freshEntries->sum('amount_usd'));

                $transaction = WalletTransaction::create([
                    'reference' => (string) Str::uuid(),
                    'type' => 'settlement_release',
                    'status' => 'completed',
                    'user_id' => $wallet->user_id,
                    'usd_amount' => $amount,
                    'net_usd_amount' => $amount,
                    'description' => 'Pending balance released after holding period.',
                    'metadata' => [
                        'wallet_id' => $wallet->id,
                        'settled_entry_ids' => $freshEntries->pluck('id')->values()->all(),
                        'cutoff' => $cutoff->toISOString(),
                    ],
                    'performed_by_user_id' => null,
                    'processed_at' => now(),
                ]);

                $this->recordEntry(
                    $transaction,
                    $wallet,
                    'debit',
                    'pending',
                    $amount,
                    'Pending balance released after holding period.',
                    ['wallet_id' => $wallet->id]
                );

                $this->recordEntry(
                    $transaction,
                    $wallet,
                    'credit',
                    'available',
                    $amount,
                    'Pending balance released after holding period.',
                    ['wallet_id' => $wallet->id]
                );

                WalletLedgerEntry::query()
                    ->whereIn('id', $freshEntries->pluck('id'))
                    ->update([
                        'settled_at' => now(),
                        'updated_at' => now(),
                    ]);

                $walletCount++;
                $entryCount += $freshEntries->count();
                $settledAmount += $amount;
            });
        }

        return [
            'wallets' => $walletCount,
            'entries' => $entryCount,
            'amount_usd' => $this->toDecimal($settledAmount),
        ];
    }

    private function recordEntry(
        WalletTransaction $transaction,
        Wallet $wallet,
        string $entryType,
        string $bucket,
        string|int|float $amountUsd,
        ?string $narration = null,
        array $metadata = [],
        ?Carbon $settlementAvailableAt = null
    ): WalletLedgerEntry {
        $amount = $this->normalizeAmount($amountUsd);

        $entry = WalletLedgerEntry::create([
            'wallet_transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'entry_type' => $entryType,
            'balance_bucket' => $bucket,
            'amount_usd' => $amount,
            'running_balance_usd' => $this->calculateRunningBalance($wallet, $bucket, $entryType, $amount),
            'narration' => $narration,
            'metadata' => $metadata,
            'settlement_available_at' => $settlementAvailableAt,
            'settled_at' => null,
        ]);

        $this->applyLedgerEntryToWallet($wallet, $bucket, $entryType, $amount);

        return $entry;
    }

    private function applyLedgerEntryToWallet(Wallet $wallet, string $bucket, string $entryType, float $amount): void
    {
        $column = match ($bucket) {
            'available' => 'available_balance_usd',
            'pending' => 'pending_balance_usd',
            'held' => 'held_balance_usd',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        $delta = $entryType === 'credit' ? $amount : -$amount;

        $wallet->forceFill([
            $column => $this->toDecimal(((float) $wallet->{$column}) + $delta),
            'last_ledger_at' => now(),
        ])->save();
    }

    private function calculateRunningBalance(Wallet $wallet, string $bucket, string $entryType, float $amount): float
    {
        $column = match ($bucket) {
            'available' => 'available_balance_usd',
            'pending' => 'pending_balance_usd',
            'held' => 'held_balance_usd',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        $current = (float) $wallet->{$column};
        $delta = $entryType === 'credit' ? $amount : -$amount;

        return $this->toDecimal($current + $delta);
    }

    private function assertWalletCanDebit(Wallet $wallet, string $bucket, float $amount, bool $allowNegativeSourceBalance = false): void
    {
        if ($allowNegativeSourceBalance) {
            return;
        }

        $column = match ($bucket) {
            'available' => 'available_balance_usd',
            'pending' => 'pending_balance_usd',
            'held' => 'held_balance_usd',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        if ((float) $wallet->{$column} < $amount) {
            throw ValidationException::withMessages([
                $column => 'Insufficient funds for this wallet operation.',
            ]);
        }
    }

    private function assertTransactionBalanced(WalletTransaction $transaction): void
    {
        $credits = (float) $transaction->entries()->where('entry_type', 'credit')->sum('amount_usd');
        $debits = (float) $transaction->entries()->where('entry_type', 'debit')->sum('amount_usd');

        if (round($credits, self::USD_SCALE) !== round($debits, self::USD_SCALE)) {
            throw new InvalidArgumentException('The ledger transaction is not balanced.');
        }
    }

    private function normalizeAmount(string|int|float $amount, int $precision = self::USD_SCALE): float
    {
        return $this->toDecimal((float) $amount, $precision);
    }

    private function holdingPeriodDays(): int
    {
        return max(0, (int) config('wallet.holding_period_days', 7));
    }

    private function toDecimal(float $amount, int $precision = self::USD_SCALE): float
    {
        return round($amount, $precision);
    }
}
