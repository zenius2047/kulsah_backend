<?php

namespace App\Services;

use App\Models\ViewedContent;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class ContentViewStateService
{
    private const VIEWER_VERSION_PREFIX = 'content:view:version:';

    public function recordView(int $viewerId, string $viewableType, int $viewableId): ViewedContent
    {
        $view = ViewedContent::query()->updateOrCreate(
            [
                'viewer_id' => $viewerId,
                'viewable_type' => $viewableType,
                'viewable_id' => $viewableId,
            ],
            [
                'viewed_at' => now(),
            ]
        );

        $this->invalidateViewer($viewerId);

        return $view;
    }

    /**
     * @return array<int, int>
     */
    public function viewedIds(int $viewerId, string $viewableType): array
    {
        return ViewedContent::query()
            ->where('viewer_id', $viewerId)
            ->where('viewable_type', $viewableType)
            ->pluck('viewable_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function viewerVersion(int $viewerId): int
    {
        return (int) $this->cacheStore()->get($this->viewerVersionKey($viewerId), 1);
    }

    public function invalidateViewer(int $viewerId): int
    {
        $version = $this->viewerVersion($viewerId) + 1;

        $this->cacheStore()->forever($this->viewerVersionKey($viewerId), $version);

        return $version;
    }

    private function cacheStore(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    private function viewerVersionKey(int $viewerId): string
    {
        return self::VIEWER_VERSION_PREFIX.$viewerId;
    }
}
