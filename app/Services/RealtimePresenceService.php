<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

class RealtimePresenceService
{
    private const PRESENCE_TTL_SECONDS = 180;
    private const LAST_SEEN_PERSIST_INTERVAL_SECONDS = 300;

    public function touch(User $user, ?int $activeConversationId = null): array
    {
        $state = [
            'status' => 'online',
            'last_seen_at' => now()->toIso8601String(),
            'active_conversation_id' => $activeConversationId,
        ];

        Redis::set($this->presenceKey($user->id), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Redis::expire($this->presenceKey($user->id), self::PRESENCE_TTL_SECONDS);

        if ($activeConversationId !== null) {
            Redis::set($this->activeConversationKey($user->id), (string) $activeConversationId);
            Redis::expire($this->activeConversationKey($user->id), self::PRESENCE_TTL_SECONDS);
        }

        $this->persistLastSeenIfNeeded($user);

        return $state;
    }

    public function setActiveConversation(User $user, ?int $conversationId): void
    {
        if ($conversationId === null) {
            Redis::del($this->activeConversationKey($user->id));
            return;
        }

        Redis::set($this->activeConversationKey($user->id), (string) $conversationId);
        Redis::expire($this->activeConversationKey($user->id), self::PRESENCE_TTL_SECONDS);
        $this->touch($user, $conversationId);
    }

    public function markOffline(User $user): void
    {
        $state = [
            'status' => 'offline',
            'last_seen_at' => now()->toIso8601String(),
            'active_conversation_id' => null,
        ];

        Redis::set($this->presenceKey($user->id), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Redis::expire($this->presenceKey($user->id), self::PRESENCE_TTL_SECONDS);
        Redis::del($this->activeConversationKey($user->id));

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function isOnline(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;
        $raw = Redis::get($this->presenceKey($userId));

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $state = json_decode($raw, true);

        if (! is_array($state)) {
            return false;
        }

        return ($state['status'] ?? null) === 'online';
    }

    public function isConversationActive(User|int $user, ?int $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        $userId = $user instanceof User ? $user->id : $user;
        return (int) Redis::get($this->activeConversationKey($userId)) === (int) $conversationId;
    }

    public function shouldSuppressPush(User $user, ?int $conversationId = null): bool
    {
        return $conversationId !== null && $this->isConversationActive($user, $conversationId);
    }

    private function persistLastSeenIfNeeded(User $user): void
    {
        $lastSeenAt = $user->last_seen_at;

        if ($lastSeenAt && now()->diffInSeconds($lastSeenAt) < self::LAST_SEEN_PERSIST_INTERVAL_SECONDS) {
            return;
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    private function presenceKey(int $userId): string
    {
        return "presence:user:{$userId}";
    }

    private function activeConversationKey(int $userId): string
    {
        return "presence:user:{$userId}:active_conversation";
    }
}
