<?php

namespace App\Domain\Challenges\Actions;

use App\Domain\Challenges\Services\ChallengeScoringEngine;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeRewardAllocation;
use App\Models\ChallengeSelectionDecision;
use App\Models\ChallengeWinner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeChallengeResults
{
    public function __construct(private readonly ChallengeScoringEngine $scoring) {}

    public function execute(Challenge $challenge, ?User $actor = null): Challenge
    {
        return DB::transaction(function () use ($challenge, $actor): Challenge {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            if ($challenge->status === ChallengeStatus::Finalized) {
                return $challenge->load('winners.allocations');
            }
            if ($challenge->status !== ChallengeStatus::ResultsPending) {
                throw ValidationException::withMessages(['challenge' => 'The challenge must be results_pending before finalization.']);
            }
            if ((bool) data_get($challenge->integrity_configuration, 'review_before_finalization', false)
                && $challenge->integrityFlags()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['integrity' => 'Open integrity flags must be resolved before finalization.']);
            }

            $entries = $challenge->entries()->where('status', 'active')
                ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($entries as $entry) {
                $this->scoring->recalculate($entry, 'finalization');
            }
            if ($challenge->judging_strategy->value === 'weighted_normalized') {
                foreach ($entries as $entry) {
                    $this->scoring->recalculate($entry, 'finalization_normalized');
                }
            }
            $ranked = in_array($challenge->winner_selection_method, ['host_selection', 'manual_admin'], true)
                ? $challenge->selectionDecisions()->where('decision', 'selected')->with('entry')->orderBy('rank')->get()->map(function (ChallengeSelectionDecision $decision) {
                    $decision->entry?->setAttribute('selected_rank', $decision->rank);

                    return $decision->entry;
                })->filter()->values()
                : $challenge->entries()->where('status', 'active')
                    ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
                    ->orderByDesc('current_score')->orderBy('submitted_at')->orderBy('id')->lockForUpdate()->get();
            $maxRank = max(0, (int) $challenge->prizes()->max('rank_to'));
            foreach ($ranked as $index => $entry) {
                $rank = (int) ($entry->selected_rank ?? ($index + 1));
                $entry->forceFill(['current_rank' => $rank, 'status' => 'finalized'])->save();
                $entry->scoreSnapshots()->latest('id')->first()?->update(['rank' => $rank]);
                if ($rank > $maxRank) {
                    continue;
                }
                $winner = ChallengeWinner::updateOrCreate(
                    ['challenge_id' => $challenge->id, 'rank' => $rank],
                    ['challenge_entry_id' => $entry->id, 'status' => 'confirmed', 'final_score' => $entry->current_score, 'confirmed_at' => now(), 'metadata' => ['selection_method' => $challenge->winner_selection_method]]
                );
                foreach ($challenge->prizes()->where('rank_from', '<=', $rank)->where('rank_to', '>=', $rank)->get() as $prize) {
                    ChallengeRewardAllocation::firstOrCreate(
                        ['winner_id' => $winner->id, 'prize_id' => $prize->id],
                        ['challenge_id' => $challenge->id, 'recipient_user_id' => $entry->creator_id, 'status' => 'pending', 'currency' => $prize->currency, 'amount' => $prize->amount, 'metadata' => ['reward_type' => $prize->reward_type->value], 'allocated_at' => now()]
                    );
                }
            }
            $challenge->forceFill(['status' => ChallengeStatus::Finalized, 'finalized_at' => now()])->save();
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $actor?->id, 'action' => 'results.finalized', 'subject_type' => Challenge::class, 'subject_id' => $challenge->id, 'after' => ['winner_count' => min($ranked->count(), $maxRank)]]);

            return $challenge->load(['winners.entry.creator', 'winners.allocations.prize']);
        });
    }
}
