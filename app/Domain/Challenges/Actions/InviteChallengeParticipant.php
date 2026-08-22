<?php

namespace App\Domain\Challenges\Actions;

use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteChallengeParticipant
{
    public function execute(Challenge $challenge, User $participant, User $actor, ?\DateTimeInterface $expiresAt = null): ChallengeInvite
    {
        return DB::transaction(function () use ($challenge, $participant, $actor, $expiresAt): ChallengeInvite {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);

            if ((int) $challenge->created_by_user_id === (int) $participant->id) {
                throw ValidationException::withMessages(['user_id' => 'The host cannot invite themselves to the challenge.']);
            }

            if ($challenge->isCreatorBattle()) {
                $existingParticipants = $challenge->collaborators()
                    ->whereIn('role', ['owner', 'challenger'])
                    ->count();

                $alreadyPresent = $challenge->collaborators()->where('user_id', $participant->id)->exists();

                if (! $alreadyPresent && $challenge->max_participants && $existingParticipants >= $challenge->max_participants) {
                    throw ValidationException::withMessages(['user_id' => 'This creator battle has reached its participant limit.']);
                }
            }

            $invite = ChallengeInvite::updateOrCreate(['challenge_id' => $challenge->id, 'invited_user_id' => $participant->id], ['invited_by_user_id' => $actor->id, 'status' => 'pending', 'token' => hash('sha256', Str::random(64)), 'expires_at' => $expiresAt, 'accepted_at' => null, 'declined_at' => null]);
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => 'participant.invited', 'subject_type' => ChallengeInvite::class, 'subject_id' => $invite->id, 'after' => ['invited_user_id' => $participant->id]]);

            return $invite;
        });
    }
}