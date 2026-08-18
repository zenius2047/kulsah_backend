<?php

namespace App\Domain\Challenges\Actions;

use App\Models\ChallengeInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptChallengeInvite
{
    public function execute(ChallengeInvite $invite, User $user): ChallengeInvite
    {
        return DB::transaction(function () use ($invite, $user): ChallengeInvite {
            $invite = ChallengeInvite::lockForUpdate()->findOrFail($invite->id);
            if ((int) $invite->invited_user_id !== (int) $user->id || $invite->status !== 'pending' || $invite->expires_at?->isPast()) {
                throw ValidationException::withMessages(['invite' => 'This invite cannot be accepted.']);
            } $invite->update(['status' => 'accepted', 'accepted_at' => now()]);

            return $invite->refresh();
        });
    }
}
