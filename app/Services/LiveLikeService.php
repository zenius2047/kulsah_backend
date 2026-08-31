<?php

namespace App\Services;

use App\Events\LiveUpdated;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

class LiveLikeService
{
    public function like(LiveSession $live, User $user, int $count = 1): array
    {
        if ($count < 1) {
            throw ValidationException::withMessages(['count' => 'Like count must be at least 1.']);
        }

        $count = min(50, $count);
        $key = $this->key($live->id);
        $total = (int) Redis::incrby($key, $count);
        Redis::expire($key, 86400);

        $live->forceFill([
            'likes_count' => max((int) $live->likes_count, $total),
        ])->saveQuietly();

        LiveUpdated::dispatch($live->fresh(), 'like_count');

        return [
            'live_id' => $live->public_id,
            'likes_count' => $total,
        ];
    }

    public function flush(LiveSession $live): int
    {
        $key = $this->key($live->id);
        $likes = (int) Redis::get($key);

        if ($likes > 0) {
            $live->forceFill([
                'likes_count' => max((int) $live->likes_count, $likes),
            ])->saveQuietly();
        }

        return $likes;
    }

    private function key(int $liveId): string
    {
        return "live:{$liveId}:likes";
    }
}

