<?php

namespace App\Services;

use App\Models\AdminConsoleRecord;
use App\Models\Onboarding;
use App\Models\VideoBoostCampaign;
use App\Models\Event;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class DiscoveryService
{
    public function __construct(
        private readonly ContentViewStateService $contentViewStateService,
        private readonly CountrySettingsService $countrySettings,
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
        $boostConfig = AdminConsoleRecord::payloadFor('video-boosting');
        $promoteBoosts = (bool) data_get($boostConfig, 'enabled', false) && in_array('discover', data_get($boostConfig, 'placements', []), true);
        $viewer = $viewerId > 0 ? User::query()->find($viewerId) : null;
        $viewerInterests = $viewerId > 0 ? (Onboarding::query()->where('user_id', $viewerId)->first()?->vibe ?? []) : [];
        $viewerInterests = is_array($viewerInterests) ? $viewerInterests : [];
        $deliveryLimits = data_get($boostConfig, 'deliveryLimits', []);
        $countryDelivery = $viewer?->country_code
            ? data_get($this->countrySettings->effective((string) $viewer->country_code), 'boosting.deliveryLimits', [])
            : [];
        $maxImpressions = max(1, (int) data_get($countryDelivery, 'maxImpressionsPerViewer', data_get($deliveryLimits, 'maxImpressionsPerViewer', 3)));
        $cooldownHours = max(1, (int) data_get($countryDelivery, 'cooldownHours', data_get($deliveryLimits, 'cooldownHours', 24)));

        $query = Video::query()
            ->with('user:id,name,username,avatar')
            ->when($promoteBoosts, fn ($query) => $query->withExists(['activeBoostCampaigns as is_sponsored' => function ($campaigns) use ($viewerId, $viewer, $viewerInterests, $boostConfig, $maxImpressions, $cooldownHours): void {
                $campaigns->where(fn ($placement) => $placement->whereJsonLength('targeting->placements', 0)->orWhereJsonContains('targeting->placements', 'discover'))
                    ->when((bool) data_get($boostConfig, 'targeting.country', false), fn ($target) => $target->where(fn ($match) => $match->whereJsonLength('targeting->countries', 0)->orWhereJsonContains('targeting->countries', strtolower((string) $viewer?->country_code))->orWhereJsonContains('targeting->countries', strtolower((string) $viewer?->country))))
                    ->when((bool) data_get($boostConfig, 'targeting.region', false), fn ($target) => $target->where(fn ($match) => $match->whereJsonLength('targeting->regions', 0)->orWhereJsonContains('targeting->regions', strtolower((string) $viewer?->location))))
                    ->when((bool) data_get($boostConfig, 'targeting.interests', false), function ($target) use ($viewerInterests): void {
                        $target->where(function ($match) use ($viewerInterests): void {
                            $match->whereJsonLength('targeting->interests', 0);
                            foreach ((array) $viewerInterests as $interest) $match->orWhereJsonContains('targeting->interests', strtolower((string) $interest));
                        });
                    })
                    ->when($viewerId > 0, function ($target) use ($viewerId, $maxImpressions, $cooldownHours): void {
                        $target->whereRaw('(select count(*) from video_boost_impressions vbi where vbi.campaign_id = video_boost_campaigns.id and vbi.viewer_id = ?) < ?', [$viewerId, $maxImpressions])
                            ->whereNotExists(function ($history) use ($viewerId, $cooldownHours): void {
                                $history->selectRaw('1')->from('video_boost_impressions as recent_boost_impressions')
                                    ->whereColumn('recent_boost_impressions.campaign_id', 'video_boost_campaigns.id')
                                    ->where('recent_boost_impressions.viewer_id', $viewerId)
                                    ->where('recent_boost_impressions.served_at', '>=', now()->subHours($cooldownHours));
                            });
                    });
            }]))
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
            ->when($promoteBoosts, fn ($query) => $query->orderByDesc('is_sponsored'))
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
        if ($promoteBoosts) {
            foreach ($videos->where('is_sponsored', true) as $video) {
                $campaign = $video->activeBoostCampaigns()->where(function ($placement): void {
                    $placement->whereJsonLength('targeting->placements', 0)->orWhereJsonContains('targeting->placements', 'discover');
                })->first();
                if ($campaign && ! $campaign->recordImpression($viewerId > 0 ? $viewerId : null)) $video->setAttribute('is_sponsored', false);
            }
        }
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
