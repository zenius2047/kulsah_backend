<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class DiscoveryService
{
    /**
     * @return array{
     *     creators:EloquentCollection<int, User>,
     *     events:EloquentCollection<int, Event>,
     *     videos:EloquentCollection<int, Video>,
     *     page:int,
     *     limit:int,
     *     has_more:bool
     * }
     */
    public function discover(int $viewerId, string $tab, int $page, int $limit, string $searchQuery = ''): array
    {
        $offset = ($page - 1) * $limit;
        $creators = new EloquentCollection;
        $events = new EloquentCollection;
        $videos = new EloquentCollection;
        $hasMore = false;

        if (in_array($tab, ['all', 'creators'], true)) {
            [$creators, $creatorsHaveMore] = $this->creators($viewerId, $searchQuery, $offset, $limit);
            $hasMore = $hasMore || $creatorsHaveMore;
        }

        if (in_array($tab, ['all', 'events'], true)) {
            [$events, $eventsHaveMore] = $this->events($searchQuery, $offset, $limit);
            $hasMore = $hasMore || $eventsHaveMore;
        }

        if (in_array($tab, ['all', 'videos'], true)) {
            [$videos, $videosHaveMore] = $this->videos($viewerId, $searchQuery, $offset, $limit);
            $hasMore = $hasMore || $videosHaveMore;
        }

        return [
            'creators' => $creators,
            'events' => $events,
            'videos' => $videos,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{EloquentCollection<int, User>, bool}
     */
    private function creators(int $viewerId, string $searchQuery, int $offset, int $limit): array
    {
        $creators = User::query()
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
            ->orderByDesc('followers_count')
            ->orderByDesc('verified')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return $this->takePage($creators, $limit);
    }

    /**
     * @return array{EloquentCollection<int, Event>, bool}
     */
    private function events(string $searchQuery, int $offset, int $limit): array
    {
        $events = Event::query()
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
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return $this->takePage($events, $limit);
    }

    /**
     * @return array{EloquentCollection<int, Video>, bool}
     */
    private function videos(int $viewerId, string $searchQuery, int $offset, int $limit): array
    {
        $videos = Video::query()
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
            ->orderByDesc('views_count')
            ->orderByDesc('likes_count')
            ->orderByDesc('created_at')
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

        return $this->takePage($videos, $limit);
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
}
