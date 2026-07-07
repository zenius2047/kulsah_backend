<?php

namespace App\Services;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FeedService
{
    private const CACHE_VERSION_KEY = 'feed:version';

    public function getFeed(int $userId, int $limit = 20, int $page = 1, array $context = []): array
    {
        $cache = $this->cacheStore();
        $version = $this->feedCacheVersion();
        $cacheKey = $this->cacheKey($userId, $limit, $page, $version, $context);

        if ($cache->has($cacheKey)) {
            return [
                'data' => $cache->get($cacheKey),
                'cache_hit' => true,
                'cache_key' => $cacheKey,
                'pagination' => $cache->get($cacheKey.':pagination', []),
            ];
        }

        $query = Video::query()
            ->ready()
            ->latest()
            ->take(max($limit * $page, $limit));

        $videos = $query->get();
        $rankedVideos = $this->rankVideosForUser($videos, $userId, $context);
        $total = $rankedVideos->count();
        $pagedVideos = $rankedVideos->forPage($page, $limit)->values();

        $payload = VideoResource::collection($pagedVideos)->resolve();
        $pagination = [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $limit)),
            'has_more_pages' => $page * $limit < $total,
        ];

        $ttl = now()->addSeconds((int) config('video.feed_cache_ttl_seconds', 600));
        $cache->put($cacheKey, $payload, $ttl);
        $cache->put($cacheKey.':pagination', $pagination, $ttl);

        return [
            'data' => $payload,
            'cache_hit' => false,
            'cache_key' => $cacheKey,
            'pagination' => $pagination,
        ];
    }

    public function invalidateFeedCaches(): int
    {
        $cache = $this->cacheStore();
        $version = (int) $cache->get(self::CACHE_VERSION_KEY, 1);
        $version++;
        $cache->forever(self::CACHE_VERSION_KEY, $version);

        return $version;
    }

    /**
     * Rank a feed collection using a reusable hook for future personalization.
     */
    public function rankVideosForUser(EloquentCollection|Collection $videos, int $userId, array $context = []): Collection
    {
        return $videos
            ->map(function (Video $video) use ($userId, $context): array {
                return [
                    'video' => $video,
                    'score' => $this->scoreVideoForUser($video, $userId, $context),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->pluck('video');
    }

    public function scoreVideoForUser(Video $video, int $userId, array $context = []): float
    {
        $recencyScore = $this->recencyScore($video);
        $personalizationScore = 0.0;

        $followedCreatorIds = array_map('intval', $context['followed_creator_ids'] ?? []);
        if ($video->user_id && in_array((int) $video->user_id, $followedCreatorIds, true)) {
            $personalizationScore += 0.25;
        }

        $interestTerms = array_filter(array_map('strtolower', $context['interest_terms'] ?? []));
        $text = strtolower(trim(implode(' ', array_filter([
            $video->title,
            $video->caption,
            data_get($video->metadata, 'topic'),
            data_get($video->metadata, 'category'),
        ]))));

        if ($interestTerms && $text !== '') {
            foreach ($interestTerms as $term) {
                if ($term !== '' && str_contains($text, $term)) {
                    $personalizationScore += 0.1;
                }
            }
        }

        $searchQuery = strtolower((string) ($context['search_query'] ?? ''));
        if ($searchQuery !== '' && str_contains($text, $searchQuery)) {
            $personalizationScore += 0.15;
        }

        return round(min(1.0, ($recencyScore * 0.7) + $personalizationScore), 4);
    }

    private function recencyScore(Video $video): float
    {
        if (! $video->created_at) {
            return 0.5;
        }

        $ageHours = max(0, now()->diffInHours($video->created_at));

        return round(exp(-($ageHours / 48)), 4);
    }

    private function cacheStore(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    private function feedCacheVersion(): int
    {
        return (int) $this->cacheStore()->get(self::CACHE_VERSION_KEY, 1);
    }

    private function cacheKey(int $userId, int $limit, int $page, int $version, array $context = []): string
    {
        $hash = substr(sha1(json_encode([
            'followed_creator_ids' => array_values(array_map('intval', $context['followed_creator_ids'] ?? [])),
            'interest_terms' => array_values(array_map('strtolower', $context['interest_terms'] ?? [])),
            'search_query' => strtolower((string) ($context['search_query'] ?? '')),
        ])), 0, 12);

        return "feed:user:{$userId}:limit:{$limit}:page:{$page}:v{$version}:{$hash}";
    }
}
