<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class CommunityFeedSessionService
{
    /**
     * @return array<int, int>
     */
    public function recentlyServedIds(int $userId): array
    {
        return array_values(array_map('intval', $this->cache()->get($this->key($userId), [])));
    }

    /**
     * @param  array<int, int|string>  $postIds
     */
    public function rememberServed(int $userId, array $postIds): void
    {
        $maximum = max(1, (int) config('community.feed.session_repeat_window', 100));
        $ids = array_values(array_unique([
            ...$this->recentlyServedIds($userId),
            ...array_map('intval', $postIds),
        ]));

        if (count($ids) > $maximum) {
            $ids = array_slice($ids, -$maximum);
        }

        $this->cache()->put(
            $this->key($userId),
            $ids,
            now()->addSeconds(max(60, (int) config('community.feed.session_ttl_seconds', 1800))),
        );
    }

    public function clear(int $userId): void
    {
        $this->cache()->forget($this->key($userId));
    }

    private function cache(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    private function key(int $userId): string
    {
        return 'community:feed:session:'.$userId;
    }
}
