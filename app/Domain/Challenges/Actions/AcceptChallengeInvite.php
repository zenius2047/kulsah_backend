<?php

namespace App\Domain\Challenges\Actions;

use App\Domain\Challenges\Services\ChallengeLifecycleService;
use App\Enums\ChallengeStatus;
use App\Models\ChallengeCollaborator;
use App\Models\ChallengeInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptChallengeInvite
{
    public function __construct(private readonly ChallengeLifecycleService $lifecycle) {}

    public function execute(ChallengeInvite $invite, User $user): ChallengeInvite
    {
        return DB::transaction(function () use ($invite, $user): ChallengeInvite {
            $invite = ChallengeInvite::query()->lockForUpdate()->with('challenge')->findOrFail($invite->id);
            $challenge = $invite->challenge()->lockForUpdate()->first();

            if (! $challenge) {
                throw ValidationException::withMessages(['invite' => 'This invite cannot be accepted.']);
            }

            if ((int) $invite->invited_user_id !== (int) $user->id || $invite->status !== 'pending' || $invite->expires_at?->isPast()) {
                throw ValidationException::withMessages(['invite' => 'This invite cannot be accepted.']);
            }

            if ($challenge->isCreatorBattle() && $challenge->status !== ChallengeStatus::AwaitingParticipants) {
                throw ValidationException::withMessages(['invite' => 'This creator battle is no longer awaiting participants.']);
            }

            $invite->update(['status' => 'accepted', 'accepted_at' => now(), 'declined_at' => null]);

            ChallengeCollaborator::updateOrCreate(
                ['challenge_id' => $challenge->id, 'user_id' => $user->id],
                [
                    'role' => $challenge->created_by_user_id === $user->id ? 'owner' : 'challenger',
                    'invited_by_user_id' => $invite->invited_by_user_id,
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]
            );

            if ($challenge->isCreatorBattle()) {
                $hasUnacceptedInvites = $challenge->invites()->where('status', '!=', 'accepted')->exists();

                if (! $hasUnacceptedInvites && $challenge->status === ChallengeStatus::AwaitingParticipants) {
                    $nextStatus = now()->lt($challenge->submission_starts_at)
                        ? ChallengeStatus::Scheduled
                        : (now()->betweenIncluded($challenge->submission_starts_at, $challenge->submission_ends_at)
                            ? ChallengeStatus::Active
                            : ChallengeStatus::SubmissionsClosed);

                    $challenge = $this->lifecycle->transition($challenge, $nextStatus, $user, [
                        'reason' => 'all_creator_battle_participants_accepted',
                    ]);
                }
            }

            return $invite->refresh();
        });
    }
}