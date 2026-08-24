<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class VideoCacheService
{
    private const ROOT_TAG = 'videos';

    private const CREATOR_VERSION_PREFIX = 'videos:creator:version:';

    private const VIEWER_VERSION_PREFIX = 'videos:viewer:version:';

    private const VIDEO_VERSION_PREFIX = 'videos:video:version:';

    public function cacheStore(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function taggedCache(array $tags): CacheRepository
    {
        $cache = $this->cacheStore();

        if (method_exists($cache, 'tags')) {
            return $cache->tags(array_values(array_unique(array_merge([self::ROOT_TAG], $tags))));
        }

        return $cache;
    }

    public function creatorVersion(int $creatorId): int
    {
        return (int) $this->cacheStore()->get($this->creatorVersionKey($creatorId), 1);
    }

    public function invalidateCreator(int $creatorId): int
    {
        $version = $this->creatorVersion($creatorId) + 1;

        $this->taggedCache($this->creatorTags($creatorId))->forever($this->creatorVersionKey($creatorId), $version);

        return $version;
    }

    public function flushCreatorCaches(int $creatorId): void
    {
        $this->taggedCache($this->creatorTags($creatorId))->flush();
    }

    public function flushAllVideoCaches(): void
    {
        $this->taggedCache([])->flush();
    }

    public function viewerVersion(int|string $viewerId): int
    {
        return (int) $this->cacheStore()->get($this->viewerVersionKey($viewerId), 1);
    }

    public function invalidateViewer(int|string $viewerId): int
    {
        $version = $this->viewerVersion($viewerId) + 1;

        $this->taggedCache($this->viewerTags($viewerId))->forever($this->viewerVersionKey($viewerId), $version);

        return $version;
    }

    public function flushViewerCaches(int|string $viewerId): void
    {
        $this->taggedCache($this->viewerTags($viewerId))->flush();
    }

    public function videoVersion(int $videoId): int
    {
        return (int) $this->cacheStore()->get($this->videoVersionKey($videoId), 1);
    }

    public function invalidateVideo(int $videoId): int
    {
        $version = $this->videoVersion($videoId) + 1;

        $this->taggedCache($this->videoTags($videoId))->forever($this->videoVersionKey($videoId), $version);

        return $version;
    }

    public function flushVideoCaches(int $videoId): void
    {
        $this->taggedCache($this->videoTags($videoId))->flush();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public function rememberCreator(int $creatorId, string $scope, array $context, Closure $resolver): mixed
    {
        $cache = $this->taggedCache($this->creatorTags($creatorId));
        $key = $this->creatorCacheKey($creatorId, $scope, $context);
        $ttlSeconds = max(60, (int) config('video.cache_ttl_seconds', 300));

        return $cache->remember($key, now()->addSeconds($ttlSeconds), $resolver);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public function rememberViewer(int|string $viewerId, string $scope, array $context, Closure $resolver): mixed
    {
        $cache = $this->taggedCache($this->viewerTags($viewerId));
        $key = $this->viewerCacheKey($viewerId, $scope, $context);
        $ttlSeconds = max(60, (int) config('video.cache_ttl_seconds', 300));

        if ($cache->has($key)) {
            $value = $cache->get($key);
            if (is_array($value) && isset($value['meta']) && is_array($value['meta'])) {
                $value['meta']['cache_hit'] = true;
            }

            return $value;
        }

        $value = $resolver();
        $cache->put($key, $value, now()->addSeconds($ttlSeconds));

        return $value;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public function rememberVideo(int $videoId, string $scope, array $context, Closure $resolver): mixed
    {
        $cache = $this->taggedCache($this->videoTags($videoId));
        $key = $this->videoCacheKey($videoId, $scope, $context);
        $ttlSeconds = max(60, (int) config('video.cache_ttl_seconds', 300));

        return $cache->remember($key, now()->addSeconds($ttlSeconds), $resolver);
    }

    /**
     * @return array<int, string>
     */
    private function creatorTags(int $creatorId): array
    {
        return ['videos:creator', "videos:creator:{$creatorId}"];
    }

    /**
     * @return array<int, string>
     */
    private function viewerTags(int|string $viewerId): array
    {
        return ['videos:viewer', 'videos:viewer:'.(string) $viewerId];
    }

    /**
     * @return array<int, string>
     */
    private function videoTags(int $videoId): array
    {
        return ['videos:video', "videos:video:{$videoId}"];
    }

    private function creatorCacheKey(int $creatorId, string $scope, array $context = []): string
    {
        $version = $this->creatorVersion($creatorId);
        $contextHash = substr(sha1(json_encode($context)), 0, 12);

        return "videos:creator:{$creatorId}:v{$version}:{$scope}:{$contextHash}";
    }

    private function creatorVersionKey(int $creatorId): string
    {
        return self::CREATOR_VERSION_PREFIX.$creatorId;
    }

    private function viewerCacheKey(int|string $viewerId, string $scope, array $context = []): string
    {
        $version = $this->viewerVersion($viewerId);
        $contextHash = substr(sha1(json_encode($context)), 0, 12);

        return 'videos:viewer:'.(string) $viewerId.":v{$version}:{$scope}:{$contextHash}";
    }

    private function viewerVersionKey(int|string $viewerId): string
    {
        return self::VIEWER_VERSION_PREFIX.(string) $viewerId;
    }

    private function videoCacheKey(int $videoId, string $scope, array $context = []): string
    {
        $version = $this->videoVersion($videoId);
        $contextHash = substr(sha1(json_encode($context)), 0, 12);

        return "videos:video:{$videoId}:v{$version}:{$scope}:{$contextHash}";
    }

    private function videoVersionKey(int $videoId): string
    {
        return self::VIDEO_VERSION_PREFIX.$videoId;
    }
}

