<?php

namespace App\Policies;

use App\Models\Challenge;
use App\Models\User;

class ChallengePolicy
{
    public function before(User $user): ?bool
    {
        return $user->roles()->where('name', 'admin')->exists() ? true : null;
    }

    public function view(?User $user, Challenge $challenge): bool
    {
        if ($challenge->visibility->value === 'public' && ! in_array($challenge->status->value, ['draft', 'pending_review', 'rejected'], true)) {
            return true;
        }

        return $user !== null && $this->manage($user, $challenge);
    }

    public function manage(User $user, Challenge $challenge): bool
    {
        return (int) $challenge->created_by_user_id === (int) $user->id
            || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->whereIn('role', ['owner', 'manager'])->exists();
    }

    public function update(User $user, Challenge $challenge): bool
    {
        return (int) $challenge->created_by_user_id === (int) $user->id
            || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->whereIn('role', ['owner', 'manager', 'editor'])->exists();
    }

    public function moderate(User $user, Challenge $challenge): bool
    {
        return $this->manage($user, $challenge) || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->whereIn('role', ['moderator', 'results_manager'])->exists();
    }

    public function finance(User $user, Challenge $challenge): bool
    {
        return $this->manage($user, $challenge) || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->where('role', 'finance_manager')->exists();
    }
}
