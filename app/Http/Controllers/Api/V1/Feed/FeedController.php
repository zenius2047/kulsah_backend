<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedCardResource;
use App\Models\Onboarding;
use App\Models\Subscription;
use App\Models\UserFollow;
use App\Models\VideoBookmark;
use App\Models\VideoLike;
use App\Models\Video;
use App\Services\FeedService;
use App\Services\VideoCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly VideoCacheService $videoCacheService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $this->validateRecommendationRequest($request);
        $context = $this->buildRecommendationContext(
            userId: (int) $request->user()->id,
            searchQuery: $validated['search_query'] ?? null,
            interestTerms: $validated['interest_terms'] ?? [],
        );
        $userId = (int) $request->user()->id;
        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $feedVersion = $this->feedService->currentFeedCacheVersion();

        $payload = $this->videoCacheService->rememberViewer(
            viewerId: $userId,
            scope: 'feed:index',
            context: [
                'feed_version' => $feedVersion,
                'limit' => $limit,
                'page' => $page,
                'search_query' => $validated['search_query'] ?? null,
                'interest_terms' => $validated['interest_terms'] ?? [],
            ],
            resolver: function () use ($request, $userId, $limit, $page, $context): array {
                $feed = $this->feedService->getFeed(
                    userId: $userId,
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

        return response()->json($payload);
    }

    public function recommendations(Request $request)
    {
        $validated = $this->validateRecommendationRequest($request);
        $context = $this->buildRecommendationContext(
            userId: (int) $request->user()->id,
            searchQuery: $validated['search_query'] ?? null,
            interestTerms: $validated['interest_terms'] ?? [],
        );
        $userId = (int) $request->user()->id;
        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $feedVersion = $this->feedService->currentFeedCacheVersion();

        $payload = $this->videoCacheService->rememberViewer(
            viewerId: $userId,
            scope: 'feed:recommendations',
            context: [
                'feed_version' => $feedVersion,
                'limit' => $limit,
                'page' => $page,
                'search_query' => $validated['search_query'] ?? null,
                'interest_terms' => $validated['interest_terms'] ?? [],
            ],
            resolver: function () use ($request, $userId, $limit, $page, $context): array {
                $feed = $this->feedService->getFeed(
                    userId: $userId,
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
            ->with('user')
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

        $likedVideoIds = VideoLike::query()
            ->where('user_id', $currentUserId)
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $bookmarkedVideoIds = VideoBookmark::query()
            ->where('user_id', $currentUserId)
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $followedCreatorIds = UserFollow::query()
            ->where('follower_id', $currentUserId)
            ->whereIn('followed_id', $creatorIds)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $subscribedCreatorIds = Subscription::query()
            ->where('subscriber_id', $currentUserId)
            ->where('status', 'active')
            ->whereIn('creator_id', $creatorIds)
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $videosById->each(function (Video $video) use ($likedVideoIds, $bookmarkedVideoIds, $followedCreatorIds, $subscribedCreatorIds): void {
            $video->setAttribute('is_liked', $likedVideoIds->has((int) $video->id));
            $video->setAttribute('is_bookmarked', $bookmarkedVideoIds->has((int) $video->id));
            $video->setAttribute('is_following', $followedCreatorIds->has((int) $video->user_id));
            $video->setAttribute('is_subscribed', $subscribedCreatorIds->has((int) $video->user_id));
        });

        return $videoIds
            ->map(static fn (int $id) => $videosById->get($id))
            ->filter()
            ->values();
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

    private function buildRecommendationContext(int $userId, ?string $searchQuery = null, array $interestTerms = []): array
    {
        $onboardingVibes = $this->getOnboardingVibes($userId);
        $followedCreatorIds = UserFollow::query()
            ->where('follower_id', $userId)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $subscribedCreatorIds = Subscription::query()
            ->where('subscriber_id', $userId)
            ->where('status', 'active')
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $likedVideoIds = VideoLike::query()
            ->where('user_id', $userId)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $bookmarkedVideoIds = VideoBookmark::query()
            ->where('user_id', $userId)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $engagedVideoIds = array_values(array_unique(array_merge($likedVideoIds, $bookmarkedVideoIds)));
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

        $peerStrength = min(1.0, ((count($followedCreatorIds) * 0.12) + (count($subscribedCreatorIds) * 0.18)) ?: 0.0);
        $hasEngagementHistory = $followedCreatorIds !== []
            || $subscribedCreatorIds !== []
            || $likedVideoIds !== []
            || $bookmarkedVideoIds !== [];
        $isInitialFeed = ! $hasEngagementHistory && $onboardingVibes !== [];
        $seedTerms = $isInitialFeed
            ? array_values(array_unique(array_merge($normalizedInterestTerms, $onboardingVibes)))
            : $normalizedInterestTerms;
        $seedCategories = array_values(array_unique(array_merge($favoriteCategories, $onboardingVibes)));

        return [
            'search_query' => $searchQuery,
            'interest_terms' => $seedTerms,
            'peer_strength' => $peerStrength,
            'followed_creator_ids' => $followedCreatorIds,
            'subscribed_creator_ids' => $subscribedCreatorIds,
            'liked_video_ids' => $likedVideoIds,
            'bookmarked_video_ids' => $bookmarkedVideoIds,
            'favorite_categories' => $isInitialFeed ? $seedCategories : $favoriteCategories,
            'favorite_creator_ids' => $favoriteCreators,
            'vibe_terms' => $onboardingVibes,
            'initial_feed' => $isInitialFeed,
            'has_engagement_history' => $hasEngagementHistory,
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
}
