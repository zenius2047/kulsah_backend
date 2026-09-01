<?php

namespace App\Services;

use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostLike;
use App\Models\CommunityPostPollVote;
use App\Models\CommunityPostShare;
use App\Models\CommunityPostView;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CommunityFeedService
{
    public function __construct(
        private readonly CommunityPostViewService $viewService,
        private readonly CommunityFeedSessionService $sessionService,
    ) {}

    public function feed(User $viewer, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $viewerId = (int) $viewer->id;
        $baseQuery = $this->accessiblePostsQuery($viewer);
        $total = (clone $baseQuery)->count();
        $recentlyServed = $this->sessionService->recentlyServedIds($viewerId);
        $candidateLimit = min(
            max($perPage, (int) config('community.feed.maximum_candidates', 500)),
            max($perPage, ($perPage * (int) config('community.feed.candidate_multiplier', 8)) + count($recentlyServed)),
        );

        $candidates = $this->withFeedRelations($baseQuery)
            ->orderByRaw('COALESCE(last_activity_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get();

        $decorated = $this->decorate($candidates, $viewer);
        $eligible = $decorated->reject(fn (CommunityPost $post) => in_array((int) $post->id, $recentlyServed, true));

        if ($eligible->isEmpty() && $decorated->isNotEmpty()) {
            $this->sessionService->clear($viewerId);
            $eligible = $decorated;
        }

        $ranked = $eligible
            ->sortByDesc(fn (CommunityPost $post) => (float) $post->getAttribute('_feed_score'))
            ->values();
        $selected = $this->applyDiversity($ranked, $perPage);

        $this->sessionService->rememberServed($viewerId, $selected->pluck('id')->all());

        return new LengthAwarePaginator(
            $selected,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function history(User $viewer, int $perPage = 20, int $page = 1, ?string $type = null): LengthAwarePaginator
    {
        $query = CommunityPostView::query()
            ->where('user_id', $viewer->id)
            ->whereHas('post', fn (Builder $query) => $this->applyAccessScope($query, $viewer))
            ->when($type && $type !== 'all', fn (Builder $query) => $query->whereHas('post', fn (Builder $postQuery) => $postQuery->where('type', $type)))
            ->with(['post' => fn ($query) => $this->withFeedRelations($query)])
            ->orderByDesc('last_viewed_at');

        $histories = $query->paginate($perPage, ['*'], 'page', $page);
        $posts = $histories->getCollection()
            ->map(function (CommunityPostView $history): ?CommunityPost {
                $post = $history->post;
                if (! $post) {
                    return null;
                }
                $post->setRelation('viewerHistory', $history);

                return $post;
            })
            ->filter()
            ->values();

        $histories->setCollection($this->decorate($posts, $viewer));

        return $histories;
    }

    private function accessiblePostsQuery(User $viewer): Builder
    {
        return $this->applyAccessScope(CommunityPost::query(), $viewer);
    }

    private function applyAccessScope(Builder $query, User $viewer): Builder
    {
        $subscribedCreatorIds = Subscription::query()
            ->where('subscriber_id', $viewer->id)
            ->where('status', 'active')
            ->pluck('creator_id');

        return $query
            ->where('status', 'published')
            ->where(function (Builder $query) use ($viewer, $subscribedCreatorIds): void {
                $query->where('audience', 'public')
                    ->orWhere('user_id', $viewer->id)
                    ->orWhere(function (Builder $query) use ($subscribedCreatorIds): void {
                        $query->where('audience', 'subscribers')
                            ->whereIn('user_id', $subscribedCreatorIds);
                    });
            });
    }

    private function withFeedRelations($query)
    {
        return $query
            ->with([
                'user.roles:id,name',
                'media',
                'pollVotes',
                'comments' => function ($query): void {
                    $query->whereNull('parent_id')
                        ->latest()
                        ->limit(3)
                        ->with([
                            'user.roles:id,name',
                            'replies' => fn ($replyQuery) => $replyQuery->oldest()->limit(3)->with('user.roles:id,name')->withCount('replies'),
                        ])
                        ->withCount('replies');
                },
            ])
            ->withCount(['likes', 'comments', 'shares', 'gifts']);
    }

    /**
     * @param  Collection<int, CommunityPost>  $posts
     * @return Collection<int, CommunityPost>
     */
    private function decorate(Collection $posts, User $viewer): Collection
    {
        if ($posts->isEmpty()) {
            return $posts;
        }

        $viewerId = (int) $viewer->id;
        $postIds = $posts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $authorIds = $posts->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
        $histories = CommunityPostView::query()
            ->where('user_id', $viewerId)
            ->whereIn('community_post_id', $postIds)
            ->get()
            ->keyBy('community_post_id');
        $likes = $this->idSet(CommunityPostLike::query()->where('user_id', $viewerId)->whereIn('community_post_id', $postIds)->pluck('community_post_id'));
        $shares = $this->idSet(CommunityPostShare::query()->where('user_id', $viewerId)->whereIn('community_post_id', $postIds)->pluck('community_post_id'));
        $comments = $this->idSet(CommunityPostComment::query()->where('user_id', $viewerId)->whereIn('community_post_id', $postIds)->distinct()->pluck('community_post_id'));
        $pollVotes = $this->idSet(CommunityPostPollVote::query()->where('user_id', $viewerId)->whereIn('community_post_id', $postIds)->pluck('community_post_id'));
        $followed = $this->idSet(UserFollow::query()->where('follower_id', $viewerId)->whereIn('followed_id', $authorIds)->pluck('followed_id'));

        return $posts->map(function (CommunityPost $post) use ($histories, $likes, $shares, $comments, $pollVotes, $followed): CommunityPost {
            $history = $post->relationLoaded('viewerHistory')
                ? $post->getRelation('viewerHistory')
                : $histories->get($post->id);
            $engaged = $likes->has((int) $post->id)
                || $shares->has((int) $post->id)
                || $comments->has((int) $post->id)
                || $pollVotes->has((int) $post->id);

            $post->setAttribute('is_liked', $likes->has((int) $post->id));
            $post->setAttribute('is_shared', $shares->has((int) $post->id));
            $post->setAttribute('has_commented', $comments->has((int) $post->id));
            $post->setAttribute('has_voted', $pollVotes->has((int) $post->id));
            $post->setAttribute('is_following', $followed->has((int) $post->user_id));
            $post->setAttribute('has_viewed', $history !== null);
            $post->setAttribute('viewer_view_count', (int) ($history?->view_count ?? 0));
            $post->setAttribute('viewer_last_viewed_at', $history?->last_viewed_at);
            $post->setAttribute('viewer_completion_percentage', (float) ($history?->max_completion_percentage ?? 0));
            $post->setAttribute('_feed_score', $this->score($post, $history, $engaged, $followed->has((int) $post->user_id)));

            return $post;
        });
    }

    private function score(CommunityPost $post, ?CommunityPostView $history, bool $engaged, bool $followed): float
    {
        $ageHours = max(0, $post->created_at?->diffInMinutes(now()) / 60);
        $recency = match (true) {
            $ageHours < 1 => 35,
            $ageHours < 6 => 29,
            $ageHours < 24 => 22,
            $ageHours < 72 => 14,
            $ageHours < 168 => 7,
            default => 2,
        };
        $engagementTotal = ((int) $post->likes_count * 2)
            + ((int) $post->comments_count * 3)
            + ((int) $post->shares_count * 4)
            + ((int) $post->gifts_count * 4);
        $engagementScore = min(25, log(1 + $engagementTotal) * 4);
        $velocity = $engagementTotal / max(1, $ageHours + 2);
        $trending = min((float) config('community.feed.trending_boost', 15), log(1 + $velocity) * 4);
        $score = $recency + $engagementScore + $trending;
        $score += $followed ? (float) config('community.feed.followed_creator_boost', 12) : 0;
        $score += $engaged ? (float) config('community.feed.engaged_post_boost', 7) : 0;

        if ($history) {
            $score -= $this->viewService->calculateRecentViewPenalty($history);
            if ($post->last_activity_at?->gt($history->last_viewed_at)) {
                $score += (float) config('community.feed.new_activity_boost', 14);
            }
            $hoursSinceView = max(0, $history->last_viewed_at?->diffInMinutes(now()) / 60);
            $completion = (float) $history->max_completion_percentage;
            if ($completion > 0 && $completion < 70 && $hoursSinceView >= 6) {
                $score += (float) config('community.feed.partial_watch_boost', 4);
            }
            if ($hoursSinceView >= (int) config('community.feed.strong_resurface_after_hours', 72)) {
                $score += 6;
            } elseif ($hoursSinceView >= (int) config('community.feed.resurface_after_hours', 24)) {
                $score += 3;
            }
        }

        return round($score, 4);
    }

    /**
     * @param  Collection<int, CommunityPost>  $ranked
     * @return Collection<int, CommunityPost>
     */
    private function applyDiversity(Collection $ranked, int $limit): Collection
    {
        $window = max(1, (int) config('community.feed.diversity_window', 10));
        $creatorMaximum = max(1, (int) config('community.feed.max_same_creator_per_window', 2));
        $typeMaximum = max(1, (int) config('community.feed.max_same_type_per_window', 3));
        $selected = collect();
        $deferred = collect();

        foreach ($ranked as $post) {
            $recent = $selected->take(-$window);
            $creatorCount = $recent->where('user_id', $post->user_id)->count();
            $typeCount = $recent->where('type', $post->type)->count();
            if ($creatorCount >= $creatorMaximum || $typeCount >= $typeMaximum) {
                $deferred->push($post);
            } else {
                $selected->push($post);
            }
            if ($selected->count() >= $limit) {
                break;
            }
        }

        if ($selected->count() < $limit) {
            $selected = $selected->concat($deferred->take($limit - $selected->count()));
        }

        return $selected->take($limit)->values();
    }

    private function idSet(Collection $ids): Collection
    {
        return $ids->map(fn ($id) => (int) $id)->flip();
    }
}
