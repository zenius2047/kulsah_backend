<?php

namespace App\Services;

use App\Models\KulCoinGift;
use App\Models\KulCoinLedgerEntry;
use App\Models\KulCoinPackage;
use App\Models\KulCoinTransaction;
use App\Models\KulCoinWallet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class KulCoinService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    public function getOrCreateUserWallet(User $user): KulCoinWallet
    {
        return KulCoinWallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'account_name' => $user->name ?: $user->username ?: 'KulCoin Wallet',
                'currency_code' => config('kulcoin.currency_code', 'KC'),
                'status' => config('kulcoin.wallet_status', 'active'),
                'available_balance_kc' => 0,
                'bonus_balance_kc' => 0,
            ]
        );
    }

    public function getOrCreateSystemWallet(string $accountKey, string $accountName): KulCoinWallet
    {
        return KulCoinWallet::firstOrCreate(
            ['account_key' => $accountKey],
            [
                'account_name' => $accountName,
                'currency_code' => config('kulcoin.currency_code', 'KC'),
                'status' => config('kulcoin.wallet_status', 'active'),
                'available_balance_kc' => 0,
                'bonus_balance_kc' => 0,
            ]
        );
    }

    public function getWalletSummary(KulCoinWallet $wallet): array
    {
        return [
            'wallet' => $wallet,
            'balances' => [
                'available_kc' => (int) $wallet->available_balance_kc,
                'bonus_kc' => (int) $wallet->bonus_balance_kc,
                'total_kc' => (int) $wallet->available_balance_kc + (int) $wallet->bonus_balance_kc,
            ],
        ];
    }

    public function listPackages(): Collection
    {
        return KulCoinPackage::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('coin_amount')
            ->get();
    }

    public function listGifts(): Collection
    {
        return KulCoinGift::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('coin_cost')
            ->orderBy('name')
            ->get();
    }

    public function purchasePackage(
        User $buyer,
        KulCoinPackage $package,
        array $data = [],
        ?User $actor = null
    ): KulCoinTransaction {
        if (! $package->is_active) {
            throw ValidationException::withMessages([
                'package_id' => 'The selected package is not available.',
            ]);
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = KulCoinTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
            }
        }

        $issuerWallet = $this->getOrCreateSystemWallet(
            config('kulcoin.issuer_account_key', 'kulcoin_issuer'),
            'KulCoin Issuer Wallet'
        );
        $promoWallet = $this->getOrCreateSystemWallet(
            config('kulcoin.promo_account_key', 'kulcoin_promo_pool'),
            'KulCoin Promotion Pool'
        );
        $buyerWallet = $this->getOrCreateUserWallet($buyer);

        return DB::transaction(function () use ($buyer, $buyerWallet, $issuerWallet, $promoWallet, $package, $data, $actor, $idempotencyKey) {
            $buyerWallet = $this->lockWallet($buyerWallet);
            $issuerWallet = $this->lockWallet($issuerWallet);
            $promoWallet = $this->lockWallet($promoWallet);

            $coinAmount = (int) $package->coin_amount;
            $bonusAmount = (int) $package->bonus_coin_amount;
            $localAmount = $this->normalizeDecimal($data['local_amount'] ?? $package->usd_price);
            $usdAmount = $this->normalizeDecimal($data['usd_amount'] ?? $package->usd_price);
            $localCurrency = strtoupper((string) ($data['local_currency'] ?? config('kulcoin.default_package_currency', 'USD')));
            $metadata = array_filter([
                'package_code' => $package->code,
                'package_name' => $package->name,
                'payment_reference' => $data['payment_reference'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'device_info' => $data['device_info'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');

            $transaction = KulCoinTransaction::create([
                'reference' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'type' => 'purchase',
                'status' => 'completed',
                'user_id' => $buyer->id,
                'counterparty_wallet_id' => $issuerWallet->id,
                'package_id' => $package->id,
                'local_currency' => $localCurrency,
                'local_amount' => $localAmount,
                'usd_amount' => $usdAmount,
                'coin_amount' => $coinAmount,
                'bonus_coin_amount' => $bonusAmount,
                'net_coin_amount' => $coinAmount + $bonusAmount,
                'description' => "Purchase of {$package->name}",
                'metadata' => $metadata,
                'performed_by_user_id' => $actor?->id ?? $buyer->id,
                'processed_at' => now(),
            ]);

            $this->recordEntry($transaction, $issuerWallet, 'debit', 'available', $coinAmount, 'KC package issuance', $metadata, allowNegativeSourceBalance: true);
            $this->recordEntry($transaction, $buyerWallet, 'credit', 'available', $coinAmount, 'KC package purchase', $metadata);

            if ($bonusAmount > 0) {
                $this->recordEntry($transaction, $promoWallet, 'debit', 'bonus', $bonusAmount, 'Promotional bonus issuance', $metadata, allowNegativeSourceBalance: true);
                $this->recordEntry($transaction, $buyerWallet, 'credit', 'bonus', $bonusAmount, 'Promotional bonus coins', $metadata);
            }

            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
        });
    }

    public function sendGift(
        User $sender,
        User $creator,
        KulCoinGift $gift,
        int $quantity = 1,
        array $data = [],
        ?User $actor = null
    ): KulCoinTransaction {
        if (! $gift->is_active) {
            throw ValidationException::withMessages([
                'gift_id' => 'The selected gift is not available.',
            ]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'The gift quantity must be at least 1.',
            ]);
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = KulCoinTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
            }
        }

        $senderWallet = $this->getOrCreateUserWallet($sender);
        $treasuryWallet = $this->getOrCreateSystemWallet(
            config('kulcoin.treasury_account_key', 'kulcoin_treasury'),
            'KulCoin Treasury Wallet'
        );

        $coinAmount = (int) $gift->coin_cost * $quantity;
        $creatorEarningsUsd = $this->calculateCreatorEarningsUsd($coinAmount);
        $description = $quantity > 1
            ? "{$quantity} x {$gift->name} gift"
            : "{$gift->name} gift";

        return DB::transaction(function () use ($sender, $creator, $gift, $quantity, $data, $actor, $idempotencyKey, $senderWallet, $treasuryWallet, $coinAmount, $creatorEarningsUsd, $description) {
            $senderWallet = $this->lockWallet($senderWallet);
            $treasuryWallet = $this->lockWallet($treasuryWallet);

            $transaction = KulCoinTransaction::create([
                'reference' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'type' => 'gift',
                'status' => 'completed',
                'user_id' => $sender->id,
                'counterparty_wallet_id' => $treasuryWallet->id,
                'gift_id' => $gift->id,
                'usd_amount' => $creatorEarningsUsd,
                'coin_amount' => $coinAmount,
                'bonus_coin_amount' => 0,
                'net_coin_amount' => $coinAmount,
                'description' => $description,
                'metadata' => array_filter([
                    'gift_code' => $gift->code,
                    'gift_name' => $gift->name,
                    'quantity' => $quantity,
                    'creator_id' => $creator->id,
                    'creator_earnings_usd' => $creatorEarningsUsd,
                    'message' => $data['message'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                    'device_info' => $data['device_info'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
                'performed_by_user_id' => $actor?->id ?? $sender->id,
                'processed_at' => now(),
            ]);

            $allocation = $this->allocateDebitBuckets($senderWallet, $coinAmount);
            foreach ($allocation as $bucket => $amount) {
                if ($amount > 0) {
                    $this->recordEntry($transaction, $senderWallet, 'debit', $bucket, $amount, 'Gift purchase', $transaction->metadata ?? []);
                }
            }

            $this->recordEntry($transaction, $treasuryWallet, 'credit', 'available', $coinAmount, 'Gift received by treasury', $transaction->metadata ?? []);

            $this->assertTransactionBalanced($transaction);

            if ($creatorEarningsUsd > 0) {
                $earningsPool = $this->walletService->getOrCreateSystemWallet(
                    config('kulcoin.creator_earnings_account_key', 'kulcoin_creator_earnings_pool'),
                    'KulCoin Creator Earnings Pool'
                );

                $this->walletService->transferBetweenWallets(
                    fromWallet: $earningsPool,
                    toWallet: $this->walletService->getOrCreateUserWallet($creator),
                    amountUsd: $creatorEarningsUsd,
                    type: 'kulcoin_gift_earnings',
                    description: "Creator earnings from {$gift->name}",
                    metadata: [
                        'kulcoin_transaction_reference' => $transaction->reference,
                        'gift_id' => $gift->id,
                        'gift_code' => $gift->code,
                        'sender_id' => $sender->id,
                    ],
                    fromBucket: 'available',
                    toBucket: 'pending',
                    actor: $actor ?? $sender,
                    allowNegativeSourceBalance: true
                );
            }

            return $transaction->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
        });
    }

    public function castVote(
        User $user,
        array $data,
        ?User $actor = null
    ): KulCoinTransaction {
        $votePrice = max(1, (int) config('kulcoin.vote_coin_price', 10));
        $voteCount = max(1, (int) ($data['vote_count'] ?? 1));
        $coinAmount = $votePrice * $voteCount;

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = KulCoinTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
            }
        }

        $wallet = $this->getOrCreateUserWallet($user);
        $treasuryWallet = $this->getOrCreateSystemWallet(
            config('kulcoin.treasury_account_key', 'kulcoin_treasury'),
            'KulCoin Treasury Wallet'
        );

        return DB::transaction(function () use ($user, $wallet, $treasuryWallet, $data, $actor, $idempotencyKey, $votePrice, $voteCount, $coinAmount) {
            $wallet = $this->lockWallet($wallet);
            $treasuryWallet = $this->lockWallet($treasuryWallet);

            $transaction = KulCoinTransaction::create([
                'reference' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'type' => 'vote',
                'status' => 'completed',
                'user_id' => $user->id,
                'counterparty_wallet_id' => $treasuryWallet->id,
                'local_currency' => null,
                'local_amount' => null,
                'usd_amount' => round($coinAmount * (float) config('kulcoin.coin_to_usd_rate', 0.01), 4),
                'coin_amount' => $coinAmount,
                'bonus_coin_amount' => 0,
                'net_coin_amount' => $coinAmount,
                'description' => 'Challenge vote purchase',
                'metadata' => array_filter([
                    'contest_type' => $data['contest_type'] ?? null,
                    'contest_id' => $data['contest_id'] ?? null,
                    'target_id' => $data['target_id'] ?? null,
                    'vote_price_kc' => $votePrice,
                    'vote_count' => $voteCount,
                    'ip_address' => $data['ip_address'] ?? null,
                    'device_info' => $data['device_info'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
                'performed_by_user_id' => $actor?->id ?? $user->id,
                'processed_at' => now(),
            ]);

            $allocation = $this->allocateDebitBuckets($wallet, $coinAmount);
            foreach ($allocation as $bucket => $amount) {
                if ($amount > 0) {
                    $this->recordEntry($transaction, $wallet, 'debit', $bucket, $amount, 'Vote purchase', $transaction->metadata ?? []);
                }
            }

            $this->recordEntry($transaction, $treasuryWallet, 'credit', 'available', $coinAmount, 'Vote received by treasury', $transaction->metadata ?? []);
            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
        });
    }

    public function issueBonusCoins(
        User $user,
        int $coins,
        ?string $reason = null,
        ?User $actor = null,
        array $metadata = []
    ): KulCoinTransaction {
        if ($coins < 1) {
            throw ValidationException::withMessages([
                'coins' => 'Bonus coins must be greater than zero.',
            ]);
        }

        $wallet = $this->getOrCreateUserWallet($user);
        $promoWallet = $this->getOrCreateSystemWallet(
            config('kulcoin.promo_account_key', 'kulcoin_promo_pool'),
            'KulCoin Promotion Pool'
        );

        return DB::transaction(function () use ($user, $wallet, $promoWallet, $coins, $reason, $actor, $metadata) {
            $wallet = $this->lockWallet($wallet);
            $promoWallet = $this->lockWallet($promoWallet);

            $transaction = KulCoinTransaction::create([
                'reference' => (string) Str::uuid(),
                'type' => 'bonus',
                'status' => 'completed',
                'user_id' => $user->id,
                'counterparty_wallet_id' => $promoWallet->id,
                'coin_amount' => 0,
                'bonus_coin_amount' => $coins,
                'net_coin_amount' => $coins,
                'description' => $reason ?? 'Promotional bonus coins',
                'metadata' => $metadata,
                'performed_by_user_id' => $actor?->id,
                'processed_at' => now(),
            ]);

            $this->recordEntry($transaction, $promoWallet, 'debit', 'bonus', $coins, $reason ?? 'Promotional bonus coins', $metadata, allowNegativeSourceBalance: true);
            $this->recordEntry($transaction, $wallet, 'credit', 'bonus', $coins, $reason ?? 'Promotional bonus coins', $metadata);

            $this->assertTransactionBalanced($transaction);

            return $transaction->load(['entries.wallet', 'entries.kulCoinTransaction', 'wallet', 'counterpartyWallet', 'package', 'gift']);
        });
    }

    private function lockWallet(KulCoinWallet $wallet): KulCoinWallet
    {
        return KulCoinWallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
    }

    private function recordEntry(
        KulCoinTransaction $transaction,
        KulCoinWallet $wallet,
        string $entryType,
        string $bucket,
        int $amountKc,
        ?string $narration = null,
        array $metadata = [],
        ?Carbon $settlementAvailableAt = null,
        bool $allowNegativeSourceBalance = false
    ): KulCoinLedgerEntry {
        if ($amountKc < 1) {
            throw new InvalidArgumentException('The coin amount must be greater than zero.');
        }

        if ($entryType === 'debit') {
            $this->assertWalletCanDebit($wallet, $bucket, $amountKc, $allowNegativeSourceBalance);
        }

        $entry = KulCoinLedgerEntry::create([
            'kulcoin_transaction_id' => $transaction->id,
            'kulcoin_wallet_id' => $wallet->id,
            'entry_type' => $entryType,
            'balance_bucket' => $bucket,
            'amount_kc' => $amountKc,
            'running_balance_kc' => $this->calculateRunningBalance($wallet, $bucket, $entryType, $amountKc),
            'narration' => $narration,
            'metadata' => $metadata,
            'settlement_available_at' => $settlementAvailableAt,
            'settled_at' => null,
        ]);

        $this->applyLedgerEntryToWallet($wallet, $bucket, $entryType, $amountKc);

        return $entry;
    }

    private function applyLedgerEntryToWallet(KulCoinWallet $wallet, string $bucket, string $entryType, int $amountKc): void
    {
        $column = match ($bucket) {
            'available' => 'available_balance_kc',
            'bonus' => 'bonus_balance_kc',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        $delta = $entryType === 'credit' ? $amountKc : -$amountKc;

        $wallet->forceFill([
            $column => max(0, (int) $wallet->{$column} + $delta),
            'last_ledger_at' => now(),
        ])->save();
    }

    private function calculateRunningBalance(KulCoinWallet $wallet, string $bucket, string $entryType, int $amountKc): int
    {
        $column = match ($bucket) {
            'available' => 'available_balance_kc',
            'bonus' => 'bonus_balance_kc',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        $current = (int) $wallet->{$column};
        $delta = $entryType === 'credit' ? $amountKc : -$amountKc;

        return max(0, $current + $delta);
    }

    private function assertWalletCanDebit(KulCoinWallet $wallet, string $bucket, int $amountKc, bool $allowNegativeSourceBalance = false): void
    {
        if ($allowNegativeSourceBalance) {
            return;
        }

        $column = match ($bucket) {
            'available' => 'available_balance_kc',
            'bonus' => 'bonus_balance_kc',
            default => throw new InvalidArgumentException("Unsupported balance bucket [{$bucket}]."),
        };

        if ((int) $wallet->{$column} < $amountKc) {
            throw ValidationException::withMessages([
                $column => 'Insufficient KulCoins for this wallet operation.',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function allocateDebitBuckets(KulCoinWallet $wallet, int $amountKc): array
    {
        $available = min((int) $wallet->available_balance_kc, $amountKc);
        $remaining = $amountKc - $available;
        $bonus = min((int) $wallet->bonus_balance_kc, $remaining);

        if (($available + $bonus) < $amountKc) {
            throw ValidationException::withMessages([
                'coins' => 'Insufficient KulCoins for this wallet operation.',
            ]);
        }

        return [
            'available' => $available,
            'bonus' => $bonus,
        ];
    }

    private function assertTransactionBalanced(KulCoinTransaction $transaction): void
    {
        $credits = (int) $transaction->entries()->where('entry_type', 'credit')->sum('amount_kc');
        $debits = (int) $transaction->entries()->where('entry_type', 'debit')->sum('amount_kc');

        if ($credits !== $debits) {
            throw new InvalidArgumentException('The KulCoin ledger transaction is not balanced.');
        }
    }

    private function calculateCreatorEarningsUsd(int $coinAmount): float
    {
        $rate = (float) config('kulcoin.coin_to_usd_rate', 0.01);
        $share = max(0, (int) config('kulcoin.creator_share_percent', 70)) / 100;

        return round($coinAmount * $rate * $share, 4);
    }

    private function normalizeDecimal(string|int|float $amount): float
    {
        return round((float) $amount, 4);
    }
}
