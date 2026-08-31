<?php

use App\Models\Challenge;
use App\Models\Conversation;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

$canAccessChallenge = function (User $user, Challenge $challenge): bool {
    return (int) $challenge->created_by_user_id === (int) $user->id
        || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->exists();
};

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('lives.{livePublicId}', function (User $user, string $livePublicId): bool {
    $live = LiveSession::query()->with('creator')->where('public_id', $livePublicId)->first();

    if (! $live) {
        return false;
    }

    if ((int) $live->creator_id === (int) $user->id) {
        return true;
    }

    return $live->viewerSessions()->where('user_id', $user->id)->exists()
        || $live->moderators()->where('user_id', $user->id)->whereNull('removed_at')->exists()
        || $live->cohosts()->where('user_id', $user->id)->whereIn('status', ['accepted', 'active'])->whereNull('removed_at')->exists();
});

Broadcast::channel('challenges.{challenge}', $canAccessChallenge);
Broadcast::channel('challenges.{challenge}.leaderboard', $canAccessChallenge);

Broadcast::channel('conversations.{conversation}', function (User $user, Conversation $conversation): bool {
    return $conversation->participants()->where('user_id', $user->id)->exists();
});

Broadcast::channel('users.{userId}.payments', function (User $user, int $userId): bool {
    return (int) $user->id === $userId;
});

