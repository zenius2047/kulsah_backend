<?php

use App\Models\Challenge;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('challenges.{challenge}', function (User $user, Challenge $challenge): bool {
    return (int) $challenge->created_by_user_id === (int) $user->id
        || $challenge->collaborators()->where('user_id', $user->id)->where('status', 'accepted')->exists();
});

Broadcast::channel('conversations.{conversation}', function (User $user, Conversation $conversation): bool {
    return $conversation->participants()->where('user_id', $user->id)->exists();
});
