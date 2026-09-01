<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedCardResource;
use App\Http\Resources\LiveSessionResource;
use App\Models\Onboarding;
use App\Models\Subscription;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoLike;
use App\Services\FeedService;
use App\Services\FeedViewerContextService;
use App\Services\LiveDiscoveryService;
use App\Services\VideoCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly VideoCacheService $videoCacheService,
        private readonly FeedViewerContextService $feedViewerContextService,
        private readonly LiveDiscoveryService $liveDiscoveryService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $this->validateRecommendationRequest($request);
        $viewer = $this->feedViewerContextService->resolve($request);
        $userId = (int) ($viewer['user_id'] ?? 0);
        $viewerKey = (string) $viewer['viewer_key'];
        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $context = $this->buildRecommendationContext(
            userId: $userId > 0 ? $userId : null,
            viewer: $viewer,
            searchQuery: $validated['search_query'] ?? null,
            interestTerms: $validated['interest_terms'] ?? [],
        );
        $feedVersion = $this->feedService->currentFeedCacheVersion();

        $payload = $this->videoCacheService->rememberViewer(
            viewerId: $viewerKey,
            scope: 'feed:index',
            context: [
                'feed_version' => $feedVersion,
                'limit' => $limit,
                'page' => $page,
                'search_query' => $validated['search_query'] ?? null,
                'interest_terms' => $validated['interest_terms'] ?? [],
                'viewer_state_updated_at' => $viewer['viewer_state_updated_at'] ?? null,
            ],
            resolver: function () use ($request, $viewerKey, $userId, $limit, $page, $context): array {
                $feed = $this->feedService->getFeed(
                    viewerKey: $viewerKey,
                    limit: $limit,
                    page: $page,
                    context: $context,
                );

                $videos = $this->loadVideosInFeedOrder(
                    collect($feed['data']),
                    $userId
                );

                return [
                    'data' => FeedCardResource::collection($videos)->resolve($request),
                    'meta' => [
                        'cache_hit' => $feed['cache_hit'],
                        'cache_key' => $feed['cache_key'],
                        'pagination' => $feed['pagination'] ?? null,
                    ],
                ];
            }
        );

        $liveStreams = $this->liveDiscoveryService->discover(
            $request->user(),
            min($limit, 20)
        )->getCollection();

        $payload['live_streams'] = LiveSessionResource::collection($liveStreams)->resolve($request);


        return response()->json($payload);
    }

    public function recommendations(Request $request)
    {
        $validated = $this->validateRecommendationRequest($request);
        $viewer = $this->feedViewerContextService->resolve($request);
        $userId = (int) ($viewer['user_id'] ?? 0);
        $viewerKey = (string) $viewer['viewer_key'];
        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $context = $this->buildRecommendationContext(
            userId: $userId > 0 ? $userId : null,
            viewer: $viewer,
            searchQuery: $validated['search_query'] ?? null,
            interestTerms: $validated['interest_terms'] ?? [],
        );
        $feedVersion = $this->feedService->currentFeedCacheVersion();

        $payload = $this->videoCacheService->rememberViewer(
            viewerId: $viewerKey,
            scope: 'feed:recommendations',
            context: [
                'feed_version' => $feedVersion,
                'limit' => $limit,
                'page' => $page,
                'search_query' => $validated['search_query'] ?? null,
                'interest_terms' => $validated['interest_terms'] ?? [],
                'viewer_state_updated_at' => $viewer['viewer_state_updated_at'] ?? null,
            ],
            resolver: function () use ($viewerKey, $userId, $limit, $page, $context): array {
                $feed = $this->feedService->getFeed(
                    viewerKey: $viewerKey,
                    limit: $limit,
                    page: $page,
                    context: $context,
                );

                $rankedIds = collect($feed['data'])
                    ->pluck('id')
                    ->filter()
                    ->map(static fn ($id) => (int) $id)
                    ->values();

                return [
                    'data' => $rankedIds,
                    'meta' => [
                        'cache_hit' => $feed['cache_hit'],
                        'cache_key' => $feed['cache_key'],
                        'pagination' => $feed['pagination'] ?? null,
                    ],
                ];
            }
        );



        return response()->json($payload);
    }

    /**
     * The feed service returns ranked identifiers and lightweight payloads.
     * We reload the models here so the response can be shaped for the mobile feed card.
     */
    private function loadVideosInFeedOrder(Collection $feedItems, int $currentUserId): Collection
    {
        $videoIds = $feedItems
            ->pluck('id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->values();

        if ($videoIds->isEmpty()) {
            return collect();
        }

        $videosById = Video::query()
            ->with(['user', 'duetSourceVideo.user', 'challengeEntries.challenge'])
            ->withCount(['likes', 'comments', 'bookmarks'])
            ->whereIn('id', $videoIds)
            ->get()
            ->keyBy('id');

        $creatorIds = $videosById
            ->pluck('user_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        $likedVideoIds = $currentUserId > 0
            ? VideoLike::query()
                ->where('user_id', $currentUserId)
                ->whereIn('video_id', $videoIds)
                ->pluck('video_id')
                ->map(static fn ($id) => (int) $id)
                ->flip()
            : collect();

        $bookmarkedVideoIds = $currentUserId > 0
            ? VideoBookmark::query()
                ->where('user_id', $currentUserId)
                ->whereIn('video_id', $videoIds)
                ->pluck('video_id')
                ->map(static fn ($id) => (int) $id)
                ->flip()
            : collect();

        $followedCreatorIds = $currentUserId > 0
            ? UserFollow::query()
                ->where('follower_id', $currentUserId)
                ->whereIn('followed_id', $creatorIds)
                ->pluck('followed_id')
                ->map(static fn ($id) => (int) $id)
                ->flip()
            : collect();

        $subscribedCreatorIds = $currentUserId > 0
            ? Subscription::query()
                ->where('subscriber_id', $currentUserId)
                ->where('status', 'active')
                ->whereIn('creator_id', $creatorIds)
                ->pluck('creator_id')
                ->map(static fn ($id) => (int) $id)
                ->flip()
            : collect();

        $videosById->each(function (Video $video) use ($likedVideoIds, $bookmarkedVideoIds, $followedCreatorIds, $subscribedCreatorIds): void {
            $video->setAttribute('is_liked', $likedVideoIds->has((int) $video->id));
            $video->setAttribute('is_bookmarked', $bookmarkedVideoIds->has((int) $video->id));
            $video->setAttribute('is_following', $followedCreatorIds->has((int) $video->user_id));
            $video->setAttribute('is_subscribed', $subscribedCreatorIds->has((int) $video->user_id));
        });

        $orderedVideos = $videoIds
            ->map(static fn (int $id) => $videosById->get($id))
            ->filter()
            ->values();

        return $this->composeFeedVideos($orderedVideos);
    }

    private function composeFeedVideos(Collection $videos): Collection
    {
        $battleVideos = $videos
            ->filter(fn (Video $video): bool => $this->isCreatorBattleVideo($video))
            ->shuffle();

        if ($battleVideos->isEmpty()) {
            return $videos;
        }

        $selectedBattle = $battleVideos->first();

        $remainingVideos = $videos
            ->reject(fn (Video $video): bool => $this->isCreatorBattleVideo($video))
            ->values();
        $insertAt = random_int(0, $remainingVideos->count());
        $remainingVideos->splice($insertAt, 0, [$selectedBattle]);

        return $remainingVideos->values();
    }

    private function isCreatorBattleVideo(Video $video): bool
    {
        if (! $video->relationLoaded('challengeEntries')) {
            return false;
        }

        return $video->challengeEntries->contains(function ($entry): bool {
            $challenge = $entry->relationLoaded('challenge') ? $entry->challenge : null;

            return $challenge?->isCreatorBattle() === true;
        });
    }

    private function validateRecommendationRequest(Request $request): array
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search_query' => ['sometimes', 'string', 'max:255'],
            'interest_terms' => ['sometimes'],
        ]);

        $interestTerms = $validated['interest_terms'] ?? [];
        if (is_string($interestTerms)) {
            $interestTerms = preg_split('/\s*,\s*/', trim($interestTerms), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $validated['interest_terms'] = is_array($interestTerms) ? $interestTerms : [];

        return $validated;
    }

    private function buildRecommendationContext(?int $userId, array $viewer, ?string $searchQuery = null, array $interestTerms = []): array
    {
        $viewerState = $viewer['viewer_state'] ?? null;
        $seenVideoIds = $this->normalizeIds($viewer['seen_video_ids'] ?? []);
        $onboardingVibes = $userId ? $this->getOnboardingVibes($userId) : [];
        $followedCreatorIds = $userId ? UserFollow::query()
            ->where('follower_id', $userId)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $subscribedCreatorIds = $userId ? Subscription::query()
            ->where('subscriber_id', $userId)
            ->where('status', 'active')
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $likedVideoIds = $userId ? VideoLike::query()
            ->where('user_id', $userId)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $bookmarkedVideoIds = $userId ? VideoBookmark::query()
            ->where('user_id', $userId)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $watchedVideoIds = $userId ? \App\Models\VideoView::query()
            ->where('user_id', $userId)
            ->orderByDesc('viewed_at')
            ->limit(500)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $engagedVideoIds = array_values(array_unique(array_merge($likedVideoIds, $bookmarkedVideoIds, $watchedVideoIds)));
        $engagedVideos = $engagedVideoIds === []
            ? collect()
            : Video::query()
                ->select(['id', 'user_id', 'content_type', 'content_types', 'metadata'])
                ->whereIn('id', $engagedVideoIds)
                ->get();

        $favoriteCategories = $engagedVideos
            ->flatMap(function (Video $video): array {
                $metadata = is_array($video->metadata) ? $video->metadata : [];

                return array_values(array_filter(array_map(
                    static fn ($value) => is_string($value) ? strtolower(trim($value)) : '',
                    array_filter([
                        $video->content_type,
                        data_get($metadata, 'category'),
                        data_get($metadata, 'topic'),
                    ])
                )));
            })
            ->unique()
            ->values()
            ->all();

        $favoriteCreators = $engagedVideos
            ->pluck('user_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $normalizedInterestTerms = array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $interestTerms
        )));

        $blockedCreatorIds = $userId ? Subscription::query()
            ->where('subscriber_id', $userId)
            ->where('status', 'blocked')
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all() : [];

        $peerStrength = min(1.0, ((count($followedCreatorIds) * 0.12) + (count($subscribedCreatorIds) * 0.18)) ?: 0.0);
        $hasEngagementHistory = $followedCreatorIds !== []
            || $subscribedCreatorIds !== []
            || $likedVideoIds !== []
            || $bookmarkedVideoIds !== []
            || $watchedVideoIds !== [];
        $isInitialFeed = ! $hasEngagementHistory && $onboardingVibes !== [];
        $seedTerms = $isInitialFeed
            ? array_values(array_unique(array_merge($normalizedInterestTerms, $onboardingVibes)))
            : $normalizedInterestTerms;
        $seedCategories = array_values(array_unique(array_merge($favoriteCategories, $onboardingVibes)));

        return [
            'user_id' => $userId,
            'search_query' => $searchQuery,
            'interest_terms' => $seedTerms,
            'peer_strength' => $peerStrength,
            'followed_creator_ids' => $followedCreatorIds,
            'subscribed_creator_ids' => $subscribedCreatorIds,
            'liked_video_ids' => $likedVideoIds,
            'bookmarked_video_ids' => $bookmarkedVideoIds,
            'watched_video_ids' => $watchedVideoIds,
            'seen_video_ids' => $seenVideoIds,
            'blocked_creator_ids' => $blockedCreatorIds,
            'favorite_categories' => $isInitialFeed ? $seedCategories : $favoriteCategories,
            'favorite_creator_ids' => $favoriteCreators,
            'vibe_terms' => $onboardingVibes,
            'initial_feed' => $isInitialFeed,
            'has_engagement_history' => $hasEngagementHistory,
            'viewer_state_updated_at' => $viewer['viewer_state_updated_at'] ?? null,
        ];
    }

    /**
     * Initial feed is based on the user's onboarding vibes before behavior history exists.
     */
    private function getOnboardingVibes(int $userId): array
    {
        $vibes = Onboarding::query()
            ->where('user_id', $userId)
            ->value('vibe');

        if (is_string($vibes)) {
            $decoded = json_decode($vibes, true);
            $vibes = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($vibes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($vibe) => is_string($vibe) ? strtolower(trim($vibe)) : '',
            $vibes
        )));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function normalizeIds(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => is_numeric($value) ? (int) $value : null,
            $values
        ), static fn ($value) => $value !== null));
    }
}


