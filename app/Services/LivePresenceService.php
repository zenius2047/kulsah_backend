<?php

namespace App\Services;

use App\Events\LiveUpdated;
use App\Models\LiveSession;
use App\Models\LiveViewerSession;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class LivePresenceService
{
    public function join(LiveSession $live, User $user): array
    {
        $presenceKey = $this->presenceKey($live->id);
        $uniqueKey = $this->uniqueKey($live->id);

        $existing = LiveViewerSession::query()
            ->where('live_session_id', $live->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->latest()
            ->first();

        if ($existing) {
            $existing->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
            Redis::sadd($presenceKey, $user->id.':'.$existing->session_key);
            Redis::expire($presenceKey, (int) config('live.presence_ttl_seconds', 90));

            $currentViewers = (int) Redis::scard($presenceKey);
            $live->forceFill([
                'current_viewers' => $currentViewers,
                'peak_viewers' => max((int) $live->peak_viewers, $currentViewers),
            ])->saveQuietly();

            LiveUpdated::dispatch($live->fresh('creator'), 'viewer_count');

            return [$existing, false];
        }

        $sessionKey = (string) Str::uuid();

        Redis::sadd($presenceKey, $user->id.':'.$sessionKey);
        Redis::expire($presenceKey, (int) config('live.presence_ttl_seconds', 90));
        $isNewUniqueViewer = (bool) Redis::sadd($uniqueKey, $user->id);
        Redis::expire($uniqueKey, 86400);

        $session = LiveViewerSession::query()->create([
            'live_session_id' => $live->id,
            'user_id' => $user->id,
            'session_key' => $sessionKey,
            'joined_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $currentViewers = (int) Redis::scard($presenceKey);
        $uniqueViewers = (int) Redis::scard($uniqueKey);

        $live->forceFill([
            'current_viewers' => $currentViewers,
            'unique_viewers' => max((int) $live->unique_viewers, $uniqueViewers),
            'peak_viewers' => max((int) $live->peak_viewers, $currentViewers),
        ])->saveQuietly();

        LiveUpdated::dispatch($live->fresh('creator'), 'viewer_count');

        return [$session, $isNewUniqueViewer];
    }

    public function leave(LiveViewerSession $session): void
    {
        if ($session->left_at) {
            return;
        }

        $session->forceFill([
            'left_at' => now(),
            'watch_seconds' => max(0, (int) floor((float) ($session->joined_at?->diffInSeconds(now()) ?? 0))),
        ])->save();

        Redis::srem($this->presenceKey($session->live_session_id), $session->user_id.':'.$session->session_key);

        $live = $session->live()->first();
        if ($live) {
            $current = (int) Redis::scard($this->presenceKey($live->id));
            $live->forceFill(['current_viewers' => $current])->saveQuietly();
            LiveUpdated::dispatch($live->fresh('creator'), 'viewer_count');
        }
    }

    public function heartbeat(LiveViewerSession $session): void
    {
        $session->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
    }

    private function presenceKey(int $liveId): string
    {
        return "live:{$liveId}:presence";
    }

    private function uniqueKey(int $liveId): string
    {
        return "live:{$liveId}:unique_viewers";
    }
}

