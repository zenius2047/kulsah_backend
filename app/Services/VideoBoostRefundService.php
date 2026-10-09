<?php

namespace App\Services;

use App\Models\AdminConsoleRecord;
use App\Models\Payment;
use App\Models\KulCoinLedgerEntry;
use App\Models\KulCoinTransaction;
use App\Models\KulCoinWallet;
use App\Models\User;
use App\Models\VideoBoostCampaign;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoBoostRefundService
{
    public function __construct(private readonly WalletService $wallets) {}

    public function refundUnused(VideoBoostCampaign $campaign, string $event, ?User $actor = null): float
    {
        return DB::transaction(function () use ($campaign, $event, $actor): float {
            $campaign = VideoBoostCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->refunded_at) return 0.0;
            $config = AdminConsoleRecord::payloadFor('video-boosting');
            $rule = data_get($campaign->targeting ?? [], "refundRules.{$event}", data_get($config, "refundRules.{$event}", 'no_refund'));
            if ($campaign->currency === 'GHS' && (! $campaign->payment_reference || ! Payment::query()->where('reference', $campaign->payment_reference)->where('status', 'successful')->exists())) {
                $campaign->forceFill(['refunded_amount' => 0, 'refunded_at' => now()])->save();
                return 0.0;
            }
            $unused = max(0, (float) $campaign->budget_amount - (float) $campaign->spent_amount);
            if ($rule === 'no_refund' || $unused <= 0) {
                $campaign->forceFill(['refunded_amount' => 0, 'refunded_at' => now()])->save();
                return 0.0;
            }
            $refund = $unused;
            if ($rule === 'prorated_unused' && $campaign->starts_at && $campaign->ends_at && $campaign->ends_at->gt($campaign->starts_at)) {
                $totalSeconds = max(1, $campaign->starts_at->diffInSeconds($campaign->ends_at));
                $remainingSeconds = max(0, now()->diffInSeconds($campaign->ends_at, false));
                $refund = min($unused, (float) $campaign->budget_amount * min(1, $remainingSeconds / $totalSeconds));
            }
            if ($campaign->currency === 'Kulcoin') {
                $coins = (int) floor($refund);
                if ($coins > 0) $this->refundKulcoins($campaign, $coins, $actor);
                $refund = (float) $coins;
            } else {
                $refund = round($refund, 2);
                if ($refund > 0) {
                    $source = $this->wallets->getOrCreateSystemWallet('video_boost_refunds', 'Video Boost Refund Clearing');
                    $recipient = $this->wallets->getOrCreateUserWallet($campaign->creator);
                    $this->wallets->transferBetweenWallets(
                        fromWallet: $source, toWallet: $recipient, amountUsd: $refund,
                        type: 'video_boost_refund', description: 'Unused funds refunded for video boost campaign',
                        metadata: ['campaign_id' => $campaign->id, 'refund_rule' => $rule],
                        fromBucket: 'available', toBucket: 'available', actor: $actor ?? $campaign->creator,
                        allowNegativeSourceBalance: true
                    );
                }
            }
            $campaign->forceFill(['refunded_amount' => $refund, 'refunded_at' => now()])->save();
            return $refund;
        });
    }

    private function refundKulcoins(VideoBoostCampaign $campaign, int $coins, ?User $actor): void
    {
        $creator = $campaign->creator;
        $wallet = KulCoinWallet::query()->firstOrCreate(['user_id' => $creator->id], [
            'account_name' => $creator->name ?: $creator->username ?: 'KulCoin Wallet',
            'currency_code' => config('kulcoin.currency_code', 'KC'), 'status' => 'active',
            'available_balance_kc' => 0, 'bonus_balance_kc' => 0,
        ]);
        $treasury = KulCoinWallet::query()->firstOrCreate(['account_key' => 'video_boost_escrow'], [
            'account_name' => 'Video Boost Kulcoin Escrow', 'currency_code' => config('kulcoin.currency_code', 'KC'),
            'status' => 'active', 'available_balance_kc' => 0, 'bonus_balance_kc' => 0,
        ]);
        $wallet = KulCoinWallet::query()->lockForUpdate()->findOrFail($wallet->id);
        $treasury = KulCoinWallet::query()->lockForUpdate()->findOrFail($treasury->id);
        $metadata = ['campaign_id' => $campaign->id, 'refund_rule' => 'unused_funds'];
        $transaction = KulCoinTransaction::query()->create([
            'reference' => (string) Str::uuid(), 'idempotency_key' => 'video-boost-refund:'.$campaign->id,
            'type' => 'video_boost_refund', 'status' => 'completed', 'user_id' => $creator->id,
            'counterparty_wallet_id' => $treasury->id, 'coin_amount' => $coins, 'bonus_coin_amount' => 0,
            'net_coin_amount' => $coins, 'usd_amount' => round($coins * (float) config('kulcoin.coin_to_usd_rate', 0.01), 4),
            'description' => 'Unused Kulcoins refunded for video boost campaign', 'metadata' => $metadata,
            'performed_by_user_id' => $actor?->id ?? $creator->id, 'processed_at' => now(),
        ]);
        $this->writeKulcoinEntry($transaction, $treasury, 'debit', $coins, $metadata);
        $this->writeKulcoinEntry($transaction, $wallet, 'credit', $coins, $metadata);
    }

    private function writeKulcoinEntry(KulCoinTransaction $transaction, KulCoinWallet $wallet, string $type, int $coins, array $metadata): void
    {
        $current = (int) $wallet->available_balance_kc;
        $next = $type === 'credit' ? $current + $coins : max(0, $current - $coins);
        KulCoinLedgerEntry::query()->create([
            'kulcoin_transaction_id' => $transaction->id, 'kulcoin_wallet_id' => $wallet->id,
            'entry_type' => $type, 'balance_bucket' => 'available', 'amount_kc' => $coins,
            'running_balance_kc' => $next, 'narration' => 'Video boost unused funds refund', 'metadata' => $metadata,
        ]);
        $wallet->forceFill(['available_balance_kc' => $next, 'last_ledger_at' => now()])->save();
    }
}
