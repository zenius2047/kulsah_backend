<?php

namespace App\Domain\Challenges\Actions;

use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeEntry;
use App\Models\ChallengeSelectionDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelectChallengeWinner
{
    public function execute(Challenge $challenge, ChallengeEntry $entry, User $actor, int $rank, string $reason): ChallengeSelectionDecision
    {
        if ((int) $entry->challenge_id !== (int) $challenge->id || $entry->status !== 'approved') {
            throw ValidationException::withMessages(['entry' => 'Only approved challenge entries may be selected.']);
        }

        return DB::transaction(function () use ($challenge, $entry, $actor, $rank, $reason): ChallengeSelectionDecision {
            Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            $decision = ChallengeSelectionDecision::updateOrCreate(['challenge_id' => $challenge->id, 'rank' => $rank, 'decision' => 'selected'], ['challenge_entry_id' => $entry->id, 'decided_by_user_id' => $actor->id, 'reason' => $reason, 'decided_at' => now()]);
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => 'winner.selected', 'subject_type' => ChallengeSelectionDecision::class, 'subject_id' => $decision->id, 'after' => $decision->toArray()]);

            return $decision;
        });
    }
}
