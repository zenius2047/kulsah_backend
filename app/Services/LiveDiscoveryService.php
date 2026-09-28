<?php

namespace App\Services;

use App\Enums\LiveStatus;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LiveDiscoveryService
{
    public function discover(?User $viewer = null, int $perPage = 20, string $searchQuery = ''): LengthAwarePaginator
    {
        $viewerId = $viewer?->id;

        return LiveSession::query()
            ->with(['creator:id,name,username,avatar,banner,verified'])
            ->withCount(['viewerSessions as active_viewer_sessions_count' => fn (Builder $query) => $query->whereNull('left_at')])
            ->whereIn('status', [LiveStatus::STARTING, LiveStatus::LIVE, LiveStatus::RECONNECTING])
            ->when($searchQuery !== '', function (Builder $query) use ($searchQuery): void {
                $query->where(function (Builder $search) use ($searchQuery): void {
                    $search->whereLike('title', '%'.$searchQuery.'%')
                        ->orWhereLike('description', '%'.$searchQuery.'%')
                        ->orWhereLike('category', '%'.$searchQuery.'%')
                        ->orWhereHas('creator', function (Builder $creator) use ($searchQuery): void {
                            $creator->whereLike('name', '%'.$searchQuery.'%')
                                ->orWhereLike('username', '%'.$searchQuery.'%');
                        });
                });
            })
            ->orderByDesc('started_at')
            ->orderByDesc('current_viewers')
            ->paginate($perPage);
    }

    public function rankCandidates(?User $viewer = null, int $limit = 20): Collection
    {
        $cacheKey = 'live:discovery:'.$viewer?->id.':'.$limit;

        return Cache::remember($cacheKey, now()->addSeconds((int) config('live.discovery_cache_seconds', 30)), function () use ($viewer, $limit) {
            return $this->discover($viewer, $limit)->getCollection();
        });
    }
}
