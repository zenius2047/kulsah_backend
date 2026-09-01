<?php

namespace App\Domain\Challenges\Actions;

use App\Models\ChallengeRewardAllocation;
use App\Models\ChallengeRewardTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessChallengeRewards
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(ChallengeRewardAllocation $allocation, ?User $actor = null): ChallengeRewardTransaction
    {
        return DB::transaction(function () use ($allocation, $actor): ChallengeRewardTransaction {
            $allocation = ChallengeRewardAllocation::with(['prize', 'winner.entry'])->lockForUpdate()->findOrFail($allocation->id);
            $key = "challenge-reward:{$allocation->id}";
            $existing = ChallengeRewardTransaction::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing?->status === 'completed') {
                return $existing;
            }
            $transaction = $existing ?? ChallengeRewardTransaction::create(['allocation_id' => $allocation->id, 'idempotency_key' => $key, 'status' => 'processing', 'attempts' => 0]);
            $transaction->increment('attempts');

            if (! in_array($allocation->prize->reward_type->value, ['cash', 'wallet_credit'], true)) {
                $allocation->update(['status' => 'awaiting_fulfillment']);
                $transaction->update(['status' => 'manual_fulfillment', 'processed_at' => now()]);

                return $transaction->refresh();
            }
            if ($allocation->currency !== 'USD') {
                throw ValidationException::withMessages(['currency' => 'Automatic wallet settlement currently requires USD.']);
            }
            $recipient = User::findOrFail($allocation->recipient_user_id);
            $source = $this->wallets->getOrCreateSystemWallet('challenge_rewards_fund', 'Challenge Rewards Fund');
            $destination = $this->wallets->getOrCreateUserWallet($recipient);
            try {
                $walletTransaction = $this->wallets->transferBetweenWallets(
                    $source, $destination, $allocation->amount, 'challenge_reward',
                    "Challenge reward allocation {$allocation->id}",
                    ['challenge_id' => $allocation->challenge_id, 'allocation_id' => $allocation->id, 'idempotency_key' => $key],
                    'available', 'pending', $actor
                );
            } catch (\Throwable $exception) {
                report($exception);
                $transaction->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000)]);
                $allocation->update(['status' => 'failed']);

                return $transaction->refresh();
            }
            $transaction->update(['status' => 'completed', 'provider' => 'kulsah_wallet', 'provider_reference' => $walletTransaction->reference, 'wallet_transaction_id' => $walletTransaction->id, 'processed_at' => now()]);
            $allocation->update(['status' => 'processed', 'processed_at' => now()]);

            return $transaction->refresh();
        });
    }
}
