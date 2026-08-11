<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class DiscoveryService
{
    public function __construct(
        private readonly ContentViewStateService $contentViewStateService,
    ) {
    }

    /**
     * @return array{
     *     creators:EloquentCollection<int, User>,
     *     events:EloquentCollection<int, Event>,
     *     videos:EloquentCollection<int, Video>,
     *     page:int,
     *     limit:int,
     *     has_more:bool,
     *     discovery_count:int,
     *     counts:array{creators:int,events:int,videos:int}
     * }
     */
    public function discover(int $viewerId, string $tab, int $page, int $limit, string $searchQuery = ''): array
    {
        $offset = ($page - 1) * $limit;
        $creators = new EloquentCollection;
        $events = new EloquentCollection;
        $videos = new EloquentCollection;
        $counts = [
            'creators' => 0,
            'events' => 0,
            'videos' => 0,
        ];
        $hasMore = false;

        if (in_array($tab, ['all', 'creators'], true)) {
            [$creators, $creatorsHaveMore, $creatorsTotal] = $this->creators(
                viewerId: $viewerId,
                searchQuery: $searchQuery,
                offset: $offset,
                limit: $limit,
            );
            $counts['creators'] = $creatorsTotal;
            $hasMore = $hasMore || $creatorsHaveMore;
        }

        if (in_array($tab, ['all', 'events'], true)) {
            [$events, $eventsHaveMore, $eventsTotal] = $this->events(
                viewerId: $viewerId,
                searchQuery: $searchQuery,
                offset: $offset,
                limit: $limit,
            );
            $counts['events'] = $eventsTotal;
            $hasMore = $hasMore || $eventsHaveMore;
        }

        if (in_array($tab, ['all', 'videos'], true)) {
            [$videos, $videosHaveMore, $videosTotal] = $this->videos(
                viewerId: $viewerId,
                searchQuery: $searchQuery,
                offset: $offset,
                limit: $limit,
            );
            $counts['videos'] = $videosTotal;
            $hasMore = $hasMore || $videosHaveMore;
        }

        return [
            'creators' => $creators,
            'events' => $events,
            'videos' => $videos,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
            'discovery_count' => array_sum($counts),
            'counts' => $counts,
        ];
    }

    /**
     * @return array{EloquentCollection<int, User>, bool, int}
     */
    private function creators(int $viewerId, string $searchQuery, int $offset, int $limit): array
    {
        $viewedCreatorIds = $this->contentViewStateService->viewedIds($viewerId, 'discovery_creator');

        $query = User::query()
            ->where('id', '!=', $viewerId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'creator'))
            ->withCount('followers')
            ->withExists([
                'followers as viewer_is_following' => fn ($query) => $query->where('follower_id', $viewerId),
                'subscriptionPlans as has_active_subscription_plan' => fn ($query) => $query->where('is_active', true),
            ])
            ->when($searchQuery !== '', function ($query) use ($searchQuery): void {
                $query->where(function ($search) use ($searchQuery): void {
                    $search->whereLike('name', '%'.$searchQuery.'%')
                        ->orWhereLike('username', '%'.$searchQuery.'%')
                        ->orWhereLike('bio', '%'.$searchQuery.'%');
                });
            })
            ->when($viewedCreatorIds !== [], fn ($query) => $query->whereNotIn('id', $viewedCreatorIds))
            ->orderByDesc('followers_count')
            ->orderByDesc('verified')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $creators = $query
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        [$creators, $hasMore] = $this->takePage($creators, $limit);
        $this->applyDiscoveryCounts($creators, $total, $offset);

        return [$creators, $hasMore, $total];
    }

    /**
     * @return array{EloquentCollection<int, Event>, bool, int}
     */
    private function events(int $viewerId, string $searchQuery, int $offset, int $limit): array
    {
        $viewedEventIds = $this->contentViewStateService->viewedIds($viewerId, 'discovery_event');

        $query = Event::query()
            ->with('creator:id,name,username,avatar')
            ->where('status', 'published')
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when($searchQuery !== '', function ($query) use ($searchQuery): void {
                $query->where(function ($search) use ($searchQuery): void {
                    $search->whereLike('title', '%'.$searchQuery.'%')
                        ->orWhereLike('description', '%'.$searchQuery.'%')
                        ->orWhereLike('category', '%'.$searchQuery.'%')
                        ->orWhereLike('venue_name', '%'.$searchQuery.'%')
                        ->orWhereLike('venue_address', '%'.$searchQuery.'%')
                        ->orWhereHas('creator', function ($creator) use ($searchQuery): void {
                            $creator->whereLike('name', '%'.$searchQuery.'%')
                                ->orWhereLike('username', '%'.$searchQuery.'%');
                        });
                });
            })
            ->when($viewedEventIds !== [], fn ($query) => $query->whereNotIn('id', $viewedEventIds))
            ->orderBy('starts_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $events = $query
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        [$events, $hasMore] = $this->takePage($events, $limit);
        $this->applyDiscoveryCounts($events, $total, $offset);

        return [$events, $hasMore, $total];
    }

    /**
     * @return array{EloquentCollection<int, Video>, bool, int}
     */
    private function videos(int $viewerId, string $searchQuery, int $offset, int $limit): array
    {
        $viewedVideoIds = $this->contentViewStateService->viewedIds($viewerId, 'discovery_video');

        $query = Video::query()
            ->with('user:id,name,username,avatar')
            ->withCount(['likes', 'comments'])
            ->withExists([
                'likes as viewer_is_liked' => fn ($query) => $query->where('user_id', $viewerId),
                'bookmarks as viewer_is_bookmarked' => fn ($query) => $query->where('user_id', $viewerId),
            ])
            ->where('status', 'ready')
            ->when($searchQuery !== '', function ($query) use ($searchQuery): void {
                $query->where(function ($search) use ($searchQuery): void {
                    $search->whereLike('title', '%'.$searchQuery.'%')
                        ->orWhereLike('caption', '%'.$searchQuery.'%')
                        ->orWhereLike('content_type', '%'.$searchQuery.'%')
                        ->orWhereHas('user', function ($creator) use ($searchQuery): void {
                            $creator->whereLike('name', '%'.$searchQuery.'%')
                                ->orWhereLike('username', '%'.$searchQuery.'%');
                        });
                });
            })
            ->when($viewedVideoIds !== [], fn ($query) => $query->whereNotIn('id', $viewedVideoIds))
            ->orderByDesc('views_count')
            ->orderByDesc('likes_count')
            ->orderByDesc('created_at');

        $total = (clone $query)->count();
        $videos = $query
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $creatorIds = $videos->pluck('user_id')->filter()->unique()->values();
        $followedCreatorIds = UserFollow::query()
            ->where('follower_id', $viewerId)
            ->whereIn('followed_id', $creatorIds)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $videos->each(function (Video $video) use ($followedCreatorIds): void {
            $video->setAttribute('viewer_is_following_creator', $followedCreatorIds->has((int) $video->user_id));
        });

        [$videos, $hasMore] = $this->takePage($videos, $limit);
        $this->applyDiscoveryCounts($videos, $total, $offset);

        return [$videos, $hasMore, $total];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentCollection<int, TModel>  $items
     * @return array{EloquentCollection<int, TModel>, bool}
     */
    private function takePage(EloquentCollection $items, int $limit): array
    {
        $hasMore = $items->count() > $limit;

        return [new EloquentCollection($items->take($limit)->all()), $hasMore];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentCollection<int, TModel>  $items
     */
    private function applyDiscoveryCounts(EloquentCollection $items, int $total, int $offset): void
    {
        $items->values()->each(function ($item, int $index) use ($total, $offset): void {
            $item->setAttribute(
                'discovery_count',
                max(0, $total - ($offset + $index + 1))
            );
        });
    }
}
