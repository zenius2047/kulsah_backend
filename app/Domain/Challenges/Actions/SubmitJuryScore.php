<?php

namespace App\Domain\Challenges\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeEntry;
use App\Models\ChallengeJuryMember;
use App\Models\ChallengeJuryScore;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitJuryScore
{
    public function execute(Challenge $challenge, ChallengeEntry $entry, User $judge, array $scores): array
    {
        if ($challenge->judging_ends_at?->isPast() || ! in_array($challenge->status, [ChallengeStatus::Judging, ChallengeStatus::SubmissionsClosed, ChallengeStatus::VotingClosed], true)) {
            throw ValidationException::withMessages(['challenge' => 'Jury scores are locked.']);
        }
        $member = ChallengeJuryMember::where('challenge_id', $challenge->id)->where('user_id', $judge->id)->where('status', 'accepted')->whereIn('role', ['judge', 'head_judge'])->first();
        if (! $member) {
            throw ValidationException::withMessages(['judge' => 'You are not an accepted jury member.']);
        }
        if ((int) $entry->challenge_id !== (int) $challenge->id || $entry->status !== 'active') {
            throw ValidationException::withMessages(['entry' => 'This entry cannot be scored.']);
        }
        $entry->loadMissing('video');
        if ($entry->video?->processing_status?->value !== 'ready' || ! $entry->video?->hls_url) {
            throw ValidationException::withMessages(['entry' => 'The entry video is not ready for judging.']);
        }

        return DB::transaction(function () use ($challenge, $entry, $judge, $member, $scores): array {
            $criteria = $challenge->juryCriteria()->get()->keyBy('id');
            $submittedCriterionIds = array_map('intval', array_column($scores, 'criterion_id'));
            sort($submittedCriterionIds);
            $expectedCriterionIds = $criteria->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($submittedCriterionIds !== $expectedCriterionIds) {
                throw ValidationException::withMessages(['scores' => 'A score is required for every criterion.']);
            }
            $saved = [];
            foreach ($scores as $item) {
                $criterion = $criteria->get($item['criterion_id']);
                if (! $criterion || $item['score'] < $criterion->min_score || $item['score'] > $criterion->max_score) {
                    throw ValidationException::withMessages(['scores' => 'A jury score is outside its criterion range.']);
                }
                $saved[] = ChallengeJuryScore::updateOrCreate(
                    ['jury_member_id' => $member->id, 'challenge_entry_id' => $entry->id, 'criterion_id' => $criterion->id],
                    ['challenge_id' => $challenge->id, 'score' => $item['score'], 'comment' => $item['comment'] ?? null, 'submitted_at' => now()]
                );
            }
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $judge->id, 'action' => 'jury_scores.submitted', 'subject_type' => ChallengeEntry::class, 'subject_id' => $entry->id, 'after' => ['criteria_count' => count($saved)]]);

            return $saved;
        });
    }
}
