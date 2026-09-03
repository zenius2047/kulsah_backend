<?php

namespace App\Services;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FeedService
{
    private const ROOT_TAG = 'feed';

    private const CACHE_VERSION_KEY = 'feed:version';

    private const FEED_RULES_VERSION = 4;

    public function __construct(
        private readonly FastApiRecommendationService $fastApiRecommendationService,
    ) {
    }

    public function getFeed(int|string $viewerKey, int $limit = 20, int $page = 1, array $context = []): array
    {
        $cache = $this->taggedCache($this->feedTags($viewerKey));
        $version = $this->feedCacheVersion();
        $cacheKey = $this->cacheKey($viewerKey, $limit, $page, $version, $context);

        if ($cache->has($cacheKey)) {
            return [
                'data' => $cache->get($cacheKey),
                'cache_hit' => true,
                'cache_key' => $cacheKey,
                'pagination' => $cache->get($cacheKey.':pagination', []),
            ];
        }

        $candidateLimit = max(80, ($limit * max(4, $page * 2)));
        $candidateVideos = $this->buildCandidatePool($limit, $page, $candidateLimit, $context);

        if ($candidateVideos->isEmpty()) {
            $candidateVideos = $this->fallbackRecentVideos($candidateLimit, $context);
        }

        $candidateVideos = $this->filterInitialVibeVideos($candidateVideos, $context);
        $rankedVideos = $this->rankVideosForUser($candidateVideos, (int) ($context['user_id'] ?? 0), $context);

        // Refill from the complete eligible pool when viewer history exhausted the feed.
        if ($rankedVideos->isEmpty()) {
            $candidateVideos = $this->fallbackRecentVideos($candidateLimit, $context, true);
            $rankedVideos = $this->rankVideosForUser($candidateVideos, (int) ($context['user_id'] ?? 0), $context);
        }
        $rankedVideos = $this->applyDiversification($rankedVideos);
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
        $cache = $this->taggedCache(['feed']);
        $version = (int) $cache->get(self::CACHE_VERSION_KEY, 1);
        $version++;
        $cache->forever(self::CACHE_VERSION_KEY, $version);

        return $version;
    }

    public function flushFeedCaches(): void
    {
        $this->taggedCache(['feed'])->flush();
    }

    public function currentFeedCacheVersion(): int
    {
        return $this->feedCacheVersion();
    }

    /**
     * Rank a feed collection using a reusable hook for future personalization.
     */
    public function rankVideosForUser(EloquentCollection|Collection $videos, int $userId, array $context = []): Collection
    {
        $aiRankedVideos = $this->fastApiRecommendationService->recommend(
            videos: collect($videos),
            userId: $userId,
            limit: count($videos),
            context: $context
        );

        if ($aiRankedVideos instanceof Collection && $aiRankedVideos->isNotEmpty()) {
            Log::debug('Feed ranked by FastAPI recommendation service.', [
                'user_id' => $userId,
                'candidate_count' => $videos->count(),
                'returned_count' => $aiRankedVideos->count(),
            ]);

            return $aiRankedVideos;
        }

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

    private function buildCandidatePool(int $limit, int $page, int $candidateLimit, array $context = []): Collection
    {
        $base = $this->eligibleVideoQuery($context);
        $seenVideoIds = array_map('intval', $context['seen_video_ids'] ?? []);
        $blockedCreatorIds = array_map('intval', $context['blocked_creator_ids'] ?? []);
        $followedCreatorIds = array_map('intval', $context['followed_creator_ids'] ?? []);
        $subscribedCreatorIds = array_map('intval', $context['subscribed_creator_ids'] ?? []);
        $favoriteCreatorIds = array_map('intval', $context['favorite_creator_ids'] ?? []);
        $interestTerms = array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $context['interest_terms'] ?? []
        )));
        $vibeTerms = array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $context['vibe_terms'] ?? []
        )));

        $candidateIds = collect();
        $bucketSize = max($candidateLimit, $limit * 6);

        $candidateIds = $candidateIds->merge($this->collectCandidateIds(
            (clone $base)->latest('created_at')->latest('id'),
            $bucketSize
        ));

        $candidateIds = $candidateIds->merge($this->collectCandidateIds(
            (clone $base)->orderByDesc('views_count')->orderByDesc('likes_count')->orderByDesc('created_at')->orderByDesc('id'),
            $bucketSize
        ));

        if ($followedCreatorIds !== []) {
            $candidateIds = $candidateIds->merge($this->collectCandidateIds(
                (clone $base)->whereIn('user_id', $followedCreatorIds)->latest('created_at')->latest('id'),
                $bucketSize
            ));
        }

        if ($subscribedCreatorIds !== []) {
            $candidateIds = $candidateIds->merge($this->collectCandidateIds(
                (clone $base)->whereIn('user_id', $subscribedCreatorIds)->latest('created_at')->latest('id'),
                $bucketSize
            ));
        }

        if ($interestTerms !== []) {
            $candidateIds = $candidateIds->merge($this->collectCandidateIds(
                $this->interestQuery(clone $base, $interestTerms, $vibeTerms),
                $bucketSize
            ));
        }

        $candidateIds = $candidateIds->merge($this->collectCandidateIds(
            (clone $base)->where(function (Builder $challengeQuery): void {
                $challengeQuery
                    ->whereIn('purpose', ['challenge_video', 'challenge_instruction_video', 'challenge_entry'])
                    ->orWhereHas('challengeEntries');
            })->latest('created_at')->latest('id'),
            $bucketSize
        ));

        $candidateIds = $candidateIds->merge($this->collectTrendingCandidateIds($base, $bucketSize));

        if ($favoriteCreatorIds !== []) {
            $candidateIds = $candidateIds->merge($this->collectCandidateIds(
                (clone $base)->whereNotIn('user_id', $favoriteCreatorIds)->latest('created_at')->latest('id'),
                $bucketSize
            ));
        }

        if ($candidateIds->isEmpty()) {
            $candidateIds = collect(
                $this->collectCandidateIds((clone $base)->latest('created_at')->latest('id'), $bucketSize)
            );
        }

        $candidateIds = $candidateIds
            ->filter()
            ->map(static fn ($id) => (int) $id)


            ->unique()
            ->take($candidateLimit)
            ->values();

        if ($candidateIds->isEmpty() && $seenVideoIds !== []) {
            $candidateIds = collect(
                $this->collectCandidateIds((clone $base)->latest('created_at')->latest('id'), $bucketSize)
            )
                ->filter()
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->take($candidateLimit)
                ->values();
        }

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        $videos = Video::query()
            ->with(['user:id,name,username,avatar,banner', 'duetSourceVideo.user:id,name,username,avatar,banner'])
            ->withCount(['likes', 'comments', 'bookmarks'])
            ->withExists(['challengeEntries'])
            ->whereIn('id', $candidateIds->all())
            ->get()
            ->keyBy('id');

        return $candidateIds
            ->map(static fn (int $id) => $videos->get($id))
            ->filter()
            ->values();
    }

    private function eligibleVideoQuery(array $context = [], bool $includeSeen = false): Builder
    {
        $query = Video::query()
            ->ready()
            ->where('visibility', 'public')
            ->with(['user:id,name,username,avatar,banner'])
            ->withCount(['likes', 'comments', 'bookmarks'])
            ->withExists(['challengeEntries']);


        $blockedCreatorIds = array_map('intval', $context['blocked_creator_ids'] ?? []);
        if ($blockedCreatorIds !== []) {
            $query->whereNotIn('user_id', $blockedCreatorIds);
        }


        return $query;
    }

    /**
     * @return array<int, int>
     */
    private function collectCandidateIds(Builder $query, int $limit): array
    {
        return $query
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Add the shared trending pool without bypassing viewer eligibility rules.
     * Redis is an optimization only; an unavailable key must not break feed delivery.
     *
     * @return array<int, int>
     */
    private function collectTrendingCandidateIds(Builder $base, int $limit): array
    {
        try {
            $ids = Redis::connection()->zrevrange(
                (string) config('video.trending_pool_key', 'feed:trending:videos'),
                0,
                max(0, $limit - 1)
            );

            if ($ids === []) {
                return [];
            }

            $allowed = (clone $base)
                ->whereIn('id', array_map('intval', $ids))
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->flip();

            return collect($ids)
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn (int $id) => $allowed->has($id))
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::debug('Trending feed pool unavailable; using database candidates.', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }
    private function interestQuery(Builder $query, array $interestTerms, array $vibeTerms): Builder
    {
        $terms = array_values(array_unique(array_merge($interestTerms, $vibeTerms)));

        return $query->where(function (Builder $search) use ($terms): void {
            foreach ($terms as $index => $term) {
                $search->{$index === 0 ? 'where' : 'orWhere'}('title', 'like', '%'.$term.'%')
                    ->orWhere('caption', 'like', '%'.$term.'%')
                    ->orWhere('content_type', 'like', '%'.$term.'%');
            }
        });
    }

    private function fallbackRecentVideos(int $candidateLimit, array $context = [], bool $includeSeen = false): Collection
    {
        $base = $this->eligibleVideoQuery($context, $includeSeen);

        return Video::query()
            ->with(['user:id,name,username,avatar,banner', 'duetSourceVideo.user:id,name,username,avatar,banner'])
            ->withCount(['likes', 'comments', 'bookmarks'])
            ->withExists(['challengeEntries'])
            ->whereIn('id', $this->collectCandidateIds((clone $base)->latest('created_at')->latest('id'), $candidateLimit))
            ->get();
    }

    private function applyDiversification(Collection $ranked): Collection
    {
        if ($ranked->isEmpty()) {
            return $ranked;
        }

        $window = max(4, (int) config('video.feed_diversity_window', 8));
        $creatorMaximum = max(1, (int) config('video.feed_max_same_creator_per_window', 2));
        $challengeMaximum = max(1, (int) config('video.feed_max_same_challenge_per_window', 2));
        $typeMaximum = max(1, (int) config('video.feed_max_same_type_per_window', 3));
        $selected = collect();
        $deferred = collect();

        foreach ($ranked as $video) {
            $recent = $selected->take(-$window);
            $creatorCount = $recent->where('user_id', $video->user_id)->count();
            $challengeCount = $recent->filter(fn (Video $candidate) => (bool) data_get($candidate, 'challengeEntries_exists', false) && (bool) data_get($video, 'challengeEntries_exists', false))->count();
            $typeCount = $recent->where('content_type', $video->content_type)->count();

            if ($creatorCount >= $creatorMaximum || $challengeCount >= $challengeMaximum || $typeCount >= $typeMaximum) {
                $deferred->push($video);
            } else {
                $selected->push($video);
            }
        }

        if ($selected->isEmpty()) {
            return $ranked->values();
        }

        if ($selected->count() < $ranked->count()) {
            $selected = $selected->concat($deferred);
        }

        return $selected->values();
    }

    private function filterInitialVibeVideos(EloquentCollection|Collection $videos, array $context = []): Collection
    {
        $isInitialFeed = (bool) ($context['initial_feed'] ?? false);
        $vibeTerms = array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $context['vibe_terms'] ?? []
        )));

        if (! $isInitialFeed || $vibeTerms === []) {
            return collect($videos);
        }

        $matchedVideos = collect($videos)
            ->filter(function (Video $video) use ($vibeTerms): bool {
                return $this->videoMatchesAnyTerm($video, $vibeTerms);
            })
            ->values();

        if ($matchedVideos->isNotEmpty()) {
            Log::debug('Initial feed filtered by onboarding vibes.', [
                'candidate_count' => $videos->count(),
                'matched_count' => $matchedVideos->count(),
                'vibes' => $vibeTerms,
            ]);

            return $matchedVideos;
        }

        Log::warning('No vibe-matching videos found for initial feed; falling back to the full candidate set.', [
            'user_id' => $context['user_id'] ?? null,
            'vibes' => $vibeTerms,
            'candidate_count' => $videos->count(),
        ]);

        return collect($videos);
    }

    private function videoMatchesAnyTerm(Video $video, array $terms): bool
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $haystack = strtolower(implode(' ', array_filter([
            $video->title,
            $video->caption,
            $video->content_type,
            is_array($video->content_types ?? null) ? implode(' ', $video->content_types) : null,
            data_get($metadata, 'topic'),
            data_get($metadata, 'category'),
        ])));

        if ($haystack === '') {
            return false;
        }

        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    public function scoreVideoForUser(Video $video, int $userId, array $context = []): float
    {
        $recencyScore = $this->recencyScore($video);
        $score = $recencyScore * 0.4;

        $followedCreatorIds = array_map('intval', $context['followed_creator_ids'] ?? []);
        $subscribedCreatorIds = array_map('intval', $context['subscribed_creator_ids'] ?? []);
        $favoriteCreatorIds = array_map('intval', $context['favorite_creator_ids'] ?? []);
        $likedVideoIds = array_map('intval', $context['liked_video_ids'] ?? []);
        $bookmarkedVideoIds = array_map('intval', $context['bookmarked_video_ids'] ?? []);
        $watchedVideoIds = array_map('intval', $context['watched_video_ids'] ?? []);
        $seenVideoIds = array_map('intval', $context['seen_video_ids'] ?? []);
        $blockedCreatorIds = array_map('intval', $context['blocked_creator_ids'] ?? []);
        $interestTerms = array_filter(array_map('strtolower', $context['interest_terms'] ?? []));
        $text = strtolower(trim(implode(' ', array_filter([
            $video->title,
            $video->caption,
            $video->content_type,
            is_array($video->content_types ?? null) ? implode(' ', $video->content_types) : null,
            data_get($video->metadata, 'topic'),
            data_get($video->metadata, 'category'),
        ]))));

        if (in_array((int) $video->user_id, $blockedCreatorIds, true)) {
            return 0.0;
        }

        if (in_array((int) $video->user_id, $followedCreatorIds, true)) {
            $score += 0.18;
        }

        if (in_array((int) $video->user_id, $subscribedCreatorIds, true)) {
            $score += 0.16;
        }

        if (in_array((int) $video->user_id, $favoriteCreatorIds, true)) {
            $score += 0.12;
        }

        if (in_array((int) $video->id, $likedVideoIds, true)) {
            $score += 0.20;
        }

        if (in_array((int) $video->id, $bookmarkedVideoIds, true)) {
            $score += 0.18;
        }

        if (in_array((int) $video->id, $watchedVideoIds, true)) {
            $score += 0.22;
        }

        if (in_array((int) $video->id, $seenVideoIds, true)) {
            $score -= 0.15;
        }

        if ($interestTerms !== [] && $text !== '') {
            foreach ($interestTerms as $term) {
                if ($term !== '' && str_contains($text, $term)) {
                    $score += 0.08;
                }
            }
        }

        if ((bool) data_get($video, 'challengeEntries_exists', false)) {
            $score += 0.06;
        }

        $engagement = (
            ((int) ($video->likes_count ?? 0) * 2)
            + ((int) ($video->comments_count ?? 0) * 3)
            + ((int) ($video->bookmarks_count ?? 0) * 4)
            + ((int) ($video->views_count ?? 0) * 0.02)
        ) / 100;

        $score += min(0.2, $engagement);

        if ($context['initial_feed'] ?? false) {
            $score += 0.04;
        }

        return round(min(1.0, max(0.0, $score)), 4);
    }

    private function recencyScore(Video $video): float
    {
        if (! $video->created_at) {
            return 0.5;
        }

        $ageHours = max(0, now()->diffInHours($video->created_at));

        return round(exp(-($ageHours / 48)), 4);
    }

    private function cacheStore()
    {
        return Cache::store(config('cache.default'));
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function taggedCache(array $tags)
    {
        $cache = $this->cacheStore();

        if (method_exists($cache, 'tags')) {
            return $cache->tags(array_values(array_unique(array_merge([self::ROOT_TAG], $tags))));
        }

        return $cache;
    }

    private function feedCacheVersion(): int
    {
        return (int) $this->taggedCache(['feed'])->get(self::CACHE_VERSION_KEY, 1);
    }

    private function cacheKey(int|string $viewerKey, int $limit, int $page, int $version, array $context = []): string
    {
        $hash = substr(sha1(json_encode([
            'feed_rules_version' => self::FEED_RULES_VERSION,
            'viewer_key' => (string) $viewerKey,
            'followed_creator_ids' => array_values(array_map('intval', $context['followed_creator_ids'] ?? [])),
            'subscribed_creator_ids' => array_values(array_map('intval', $context['subscribed_creator_ids'] ?? [])),
            'liked_video_ids' => array_values(array_map('intval', $context['liked_video_ids'] ?? [])),
            'bookmarked_video_ids' => array_values(array_map('intval', $context['bookmarked_video_ids'] ?? [])),
            'watched_video_ids' => array_values(array_map('intval', $context['watched_video_ids'] ?? [])),
            'seen_video_ids' => array_values(array_map('intval', $context['seen_video_ids'] ?? [])),
            'blocked_creator_ids' => array_values(array_map('intval', $context['blocked_creator_ids'] ?? [])),
            'interest_terms' => array_values(array_map('strtolower', $context['interest_terms'] ?? [])),
            'search_query' => strtolower((string) ($context['search_query'] ?? '')),
            'initial_feed' => (bool) ($context['initial_feed'] ?? false),
            'vibe_terms' => array_values(array_map('strtolower', $context['vibe_terms'] ?? [])),
            'viewer_state_updated_at' => $context['viewer_state_updated_at'] ?? null,
        ])), 0, 12);

        return 'feed:viewer:'.(string) $viewerKey.":limit:{$limit}:page:{$page}:v{$version}:{$hash}";
    }

    /**
     * @return array<int, string>
     */
    private function feedTags(int|string $viewerKey): array
    {
        return ['feed:user', 'feed:user:'.(string) $viewerKey];
    }
}

