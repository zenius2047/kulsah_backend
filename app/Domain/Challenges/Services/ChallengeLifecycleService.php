<?php

namespace App\Domain\Challenges\Services;

use App\Domain\Challenges\Exceptions\InvalidChallengeTransition;
use App\Enums\ChallengeStatus;
use App\Events\ChallengeStatusChanged;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChallengeLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['pending_review', 'cancelled'],
        'pending_review' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['scheduled', 'active', 'cancelled'],
        'scheduled' => ['active', 'paused', 'cancelled'],
        'active' => ['paused', 'submissions_closed', 'cancelled', 'voided'],
        'paused' => ['scheduled', 'active', 'cancelled', 'voided'],
        'submissions_closed' => ['voting_closed', 'judging', 'integrity_review', 'cancelled'],
        'voting_closed' => ['judging', 'integrity_review'],
        'judging' => ['integrity_review', 'results_pending'],
        'integrity_review' => ['results_pending', 'voided'],
        'results_pending' => ['finalized', 'integrity_review'],
        'finalized' => ['rewards_processing', 'completed', 'voided'],
        'rewards_processing' => ['completed'],
        'completed' => ['archived'],
        'rejected' => ['draft', 'archived'],
        'cancelled' => ['archived'],
        'voided' => ['archived'],
    ];

    public function transition(Challenge $challenge, ChallengeStatus $to, ?User $actor = null, array $metadata = []): Challenge
    {
        return DB::transaction(function () use ($challenge, $to, $actor, $metadata): Challenge {
            $locked = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            $from = $locked->status->value;

            if (! in_array($to->value, self::TRANSITIONS[$from] ?? [], true)) {
                throw new InvalidChallengeTransition("Challenge cannot transition from {$from} to {$to->value}.");
            }

            if (in_array($to, [ChallengeStatus::Scheduled, ChallengeStatus::Active], true)
                && $locked->media()->whereIn('role', ['challenge_video', 'instruction_video'])
                    ->whereHas('video', fn ($query) => $query->where('processing_status', '!=', 'ready')->orWhereNull('hls_url'))
                    ->exists()) {
                throw ValidationException::withMessages(['media' => 'Required challenge videos must finish HLS processing before publication.']);
            }

            $updates = ['status' => $to];
            if ($to === ChallengeStatus::Active && ! $locked->published_at) {
                $updates['published_at'] = now();
            }
            if ($to === ChallengeStatus::Finalized) {
                $updates['finalized_at'] = now();
            }
            if ($to === ChallengeStatus::Cancelled) {
                $updates['cancelled_at'] = now();
            }
            $locked->forceFill($updates)->save();

            ChallengeAuditLog::create([
                'challenge_id' => $locked->id, 'actor_user_id' => $actor?->id,
                'action' => 'status.transitioned', 'subject_type' => Challenge::class,
                'subject_id' => $locked->id, 'before' => ['status' => $from],
                'after' => ['status' => $to->value], 'metadata' => $metadata,
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            ChallengeStatusChanged::dispatch($locked);

            return $locked->refresh();
        });
    }
}
