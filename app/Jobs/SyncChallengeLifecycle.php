<?php

namespace App\Jobs;

use App\Domain\Challenges\Services\ChallengeLifecycleService;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncChallengeLifecycle implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $challengeId) {}

    public function handle(ChallengeLifecycleService $lifecycle): void
    {
        $challenge = Challenge::find($this->challengeId);
        if (! $challenge) {
            return;
        }

        $target = match ($challenge->status) {
            ChallengeStatus::Approved => $challenge->submission_starts_at->isFuture() ? ChallengeStatus::Scheduled : ChallengeStatus::Active,
            ChallengeStatus::Scheduled => $challenge->submission_starts_at->isPast() ? ChallengeStatus::Active : null,
            ChallengeStatus::Active => $challenge->submission_ends_at->isPast() ? ChallengeStatus::SubmissionsClosed : null,
            ChallengeStatus::SubmissionsClosed => $challenge->voting_ends_at?->isPast()
                ? ChallengeStatus::VotingClosed
                : (! $challenge->voting_ends_at ? ($challenge->judging_ends_at ? ChallengeStatus::Judging : $this->postJudging($challenge)) : null),
            ChallengeStatus::VotingClosed => $challenge->judging_ends_at ? ChallengeStatus::Judging : $this->postJudging($challenge),
            ChallengeStatus::Judging => $challenge->judging_ends_at?->isPast() ? $this->postJudging($challenge) : null,
            ChallengeStatus::IntegrityReview => ! $challenge->integrityFlags()->where('status', 'open')->exists() ? ChallengeStatus::ResultsPending : null,
            default => null,
        };
        if ($target) {
            $lifecycle->transition($challenge, $target, metadata: ['source' => 'scheduler']);
        }
    }

    private function postJudging(Challenge $challenge): ChallengeStatus
    {
        return (bool) data_get($challenge->integrity_configuration, 'review_before_finalization', false)
            ? ChallengeStatus::IntegrityReview : ChallengeStatus::ResultsPending;
    }
}
