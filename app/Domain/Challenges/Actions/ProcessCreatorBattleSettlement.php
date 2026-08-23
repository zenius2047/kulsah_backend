<?php

namespace App\Domain\Challenges\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeBallotChoice;
use App\Models\ChallengeWinner;
use App\Models\CreatorBattleSettlement;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessCreatorBattleSettlement
{
    public function __construct(private readonly WalletService $wallets) {}

    public function execute(Challenge $challenge, ?User $actor = null): CreatorBattleSettlement
    {
        return DB::transaction(function () use ($challenge, $actor): CreatorBattleSettlement {
            $challenge = Challenge::query()->with(['winners.entry.creator'])->lockForUpdate()->findOrFail($challenge->id);

            if (! in_array($challenge->status, [ChallengeStatus::Finalized, ChallengeStatus::RewardsProcessing, ChallengeStatus::Completed], true)) {
                throw ValidationException::withMessages(['challenge' => 'Vote settlement can only run after the challenge is finalized or completed.']);
            }

            if (! $challenge->voting_starts_at || ! $challenge->voting_ends_at) {
                throw ValidationException::withMessages(['challenge' => 'This challenge does not have a voting window to settle.']);
            }

            $winner = $challenge->winners()->with(['entry.creator', 'entry.video'])->orderBy('rank')->first();
            if (! $winner instanceof ChallengeWinner || ! $winner->entry || ! $winner->entry->creator) {
                throw ValidationException::withMessages(['winner' => 'A winning entry must exist before settlement can run.']);
            }

            $settlementKey = "challenge-vote-settlement:{$challenge->id}";
            $existing = CreatorBattleSettlement::query()->where('idempotency_key', $settlementKey)->lockForUpdate()->first();
            if ($existing?->status === 'completed') {
                return $existing;
            }

            $settlement = $existing ?? CreatorBattleSettlement::create([
                'challenge_id' => $challenge->id,
                'challenge_winner_id' => $winner->id,
                'challenge_entry_id' => $winner->challenge_entry_id,
                'recipient_user_id' => $winner->entry->creator_id,
                'status' => 'processing',
                'idempotency_key' => $settlementKey,
                'metadata' => [
                    'challenge_title' => $challenge->title,
                    'winner_rank' => $winner->rank,
                ],
            ]);

            $settlement->increment('attempts');

            $voteCount = (int) ChallengeBallotChoice::query()
                ->whereHas('ballot', fn ($query) => $query->where('challenge_id', $challenge->id)->where('status', 'submitted'))
                ->count();
            $votePrice = max(1, (int) config('kulcoin.vote_coin_price', 10));
            $coinAmount = $voteCount * $votePrice;
            $conversionRate = (float) config('kulcoin.coin_to_usd_rate', 0.01);
            $usdAmount = round($coinAmount * $conversionRate, 4);

            if ($coinAmount < 1 || $usdAmount <= 0) {
                throw ValidationException::withMessages(['votes' => 'There are no settled votes available for this challenge.']);
            }

            $challenge->forceFill(['status' => ChallengeStatus::RewardsProcessing])->save();

            $source = $this->wallets->getOrCreateSystemWallet('challenge_vote_payout_fund', 'Challenge Vote Payout Fund');
            $destination = $this->wallets->getOrCreateUserWallet($winner->entry->creator);

            try {
                $walletTransaction = $this->wallets->transferBetweenWallets(
                    fromWallet: $source,
                    toWallet: $destination,
                    amountUsd: $usdAmount,
                    type: 'challenge_vote_settlement',
                    description: "Vote settlement payout for challenge {$challenge->id}",
                    metadata: [
                        'challenge_id' => $challenge->id,
                        'challenge_title' => $challenge->title,
                        'challenge_winner_id' => $winner->id,
                        'challenge_entry_id' => $winner->challenge_entry_id,
                        'recipient_user_id' => $winner->entry->creator_id,
                        'vote_count' => $voteCount,
                        'vote_coin_price' => $votePrice,
                        'vote_coin_amount' => $coinAmount,
                        'conversion_rate' => $conversionRate,
                        'idempotency_key' => $settlementKey,
                    ],
                    fromBucket: 'available',
                    toBucket: 'available',
                    actor: $actor
                );
            } catch (\Throwable $exception) {
                report($exception);
                $settlement->update([
                    'status' => 'failed',
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                $challenge->forceFill(['status' => ChallengeStatus::Finalized])->save();

                return $settlement->refresh();
            }

            $settlement->update([
                'challenge_winner_id' => $winner->id,
                'challenge_entry_id' => $winner->challenge_entry_id,
                'recipient_user_id' => $winner->entry->creator_id,
                'status' => 'completed',
                'vote_count' => $voteCount,
                'vote_coin_amount' => $coinAmount,
                'conversion_rate' => $conversionRate,
                'usd_amount' => $usdAmount,
                'wallet_transaction_id' => $walletTransaction->id,
                'processed_at' => now(),
                'metadata' => array_merge($settlement->metadata ?? [], [
                    'wallet_transaction_reference' => $walletTransaction->reference,
                ]),
            ]);

            $challenge->forceFill(['status' => ChallengeStatus::Completed])->save();

            ChallengeAuditLog::create([
                'challenge_id' => $challenge->id,
                'actor_user_id' => $actor?->id,
                'action' => 'challenge_votes.settled',
                'subject_type' => CreatorBattleSettlement::class,
                'subject_id' => $settlement->id,
                'after' => $settlement->fresh()->toArray(),
            ]);

            return $settlement->fresh();
        });
    }
}