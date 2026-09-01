<?php

namespace App\Services;

use App\Events\CommunityPostViewed;
use App\Events\CommunityVideoWatched;
use App\Models\CommunityPost;
use App\Models\CommunityPostView;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommunityPostViewService
{
    public function __construct(private readonly ContentViewStateService $contentViewStateService) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{view:CommunityPostView|null,meaningful:bool,counted:bool}
     */
    public function recordView(User $user, CommunityPost $post, array $data): array
    {
        $watchDuration = round(max(0, (float) ($data['watch_duration_seconds'] ?? 0)), 3);
        $completion = round(min(100, max(0, (float) ($data['completion_percentage'] ?? 0))), 2);
        $completed = (bool) ($data['completed'] ?? false) || $completion >= 99.5;
        $meaningful = $this->isMeaningful($post, $data, $watchDuration, $completed);

        if (! $meaningful) {
            return ['view' => null, 'meaningful' => false, 'counted' => false];
        }

        $result = DB::transaction(function () use ($user, $post, $watchDuration, $completion, $completed): array {
            $now = now();
            $view = CommunityPostView::query()
                ->where('user_id', $user->id)
                ->where('community_post_id', $post->id)
                ->lockForUpdate()
                ->first();

            $view ??= new CommunityPostView([
                'user_id' => $user->id,
                'community_post_id' => $post->id,
                'first_viewed_at' => $now,
                'view_count' => 0,
                'watch_duration_seconds' => 0,
                'completed_count' => 0,
            ]);

            $cooldown = max(0, (int) config('community.views.public_count_cooldown_seconds', 30));
            $counted = $view->last_counted_at === null
                || $view->last_counted_at->lte($now->copy()->subSeconds($cooldown));

            $view->forceFill([
                'last_viewed_at' => $now,
                'view_count' => (int) $view->view_count + ($counted ? 1 : 0),
                'watch_duration_seconds' => round((float) $view->watch_duration_seconds + ($counted ? $watchDuration : 0), 3),
                'last_watch_duration_seconds' => $watchDuration,
                'completion_percentage' => $completion,
                'max_completion_percentage' => max((float) $view->max_completion_percentage, $completion),
                'completed_count' => (int) $view->completed_count + ($counted && $completed ? 1 : 0),
                'reached_25_percent' => $view->reached_25_percent || $completion >= 25,
                'reached_50_percent' => $view->reached_50_percent || $completion >= 50,
                'reached_75_percent' => $view->reached_75_percent || $completion >= 75,
                'reached_90_percent' => $view->reached_90_percent || $completion >= 90,
                'last_counted_at' => $counted ? $now : $view->last_counted_at,
            ])->save();

            if ($counted) {
                CommunityPost::query()->whereKey($post->id)->increment('views_count');
            }

            return ['view' => $view->fresh(), 'counted' => $counted];
        });

        $this->contentViewStateService->recordView((int) $user->id, 'community_post', (int) $post->id);
        CommunityPostViewed::dispatch($result['view'], $result['counted']);
        if ($post->type === 'video' && $watchDuration > 0) {
            CommunityVideoWatched::dispatch($result['view']);
        }

        return ['view' => $result['view'], 'meaningful' => true, 'counted' => $result['counted']];
    }

    public function markEngaged(User $user, CommunityPost $post): void
    {
        DB::transaction(function () use ($user, $post): void {
            CommunityPostView::query()
                ->where('user_id', $user->id)
                ->where('community_post_id', $post->id)
                ->update([
                    'engaged' => true,
                    'last_engaged_at' => now(),
                    'updated_at' => now(),
                ]);

            CommunityPost::query()->whereKey($post->id)->update([
                'last_activity_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->contentViewStateService->invalidateViewer((int) $user->id);
    }

    public function touchActivity(CommunityPost $post): void
    {
        CommunityPost::query()->whereKey($post->id)->update([
            'last_activity_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function calculateRecentViewPenalty(CommunityPostView $view): float
    {
        $hours = max(0, $view->last_viewed_at?->diffInMinutes(now()) / 60);
        $base = (float) config('community.feed.recent_view_penalty', 30);
        $penalty = match (true) {
            $hours < 1 => $base,
            $hours < 6 => $base * 0.8,
            $hours < 24 => $base * 0.52,
            $hours < 72 => $base * 0.23,
            default => 0.0,
        };

        $penalty += min(12, max(0, (int) $view->view_count - 1) * (float) config('community.feed.repeat_view_penalty', 4));
        $completion = (float) $view->max_completion_percentage;
        if ($completion < 25) {
            $penalty *= 0.65;
        } elseif ($completion < 70) {
            $penalty *= 0.8;
        } elseif ($completion >= 90) {
            $penalty *= 1.15;
        }

        return round($penalty, 4);
    }

    public function shouldAllowResurface(CommunityPost $post, CommunityPostView $view): bool
    {
        if ($post->last_activity_at?->gt($view->last_viewed_at)) {
            return true;
        }

        $hours = $view->last_viewed_at?->diffInHours(now()) ?? PHP_INT_MAX;

        return $hours >= (int) config('community.feed.resurface_after_hours', 24)
            || ((float) $view->max_completion_percentage < 70 && $hours >= 6)
            || $view->engaged;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isMeaningful(CommunityPost $post, array $data, float $watchDuration, bool $completed): bool
    {
        if ($post->type === 'video') {
            return $completed || $watchDuration >= (float) config('community.views.minimum_video_watch_seconds', 1);
        }

        $visiblePercentage = (float) ($data['visible_percentage'] ?? 100);
        $visibleSeconds = (float) ($data['visible_duration_seconds'] ?? config('community.views.minimum_visible_seconds', 1.5));

        return $visiblePercentage >= (float) config('community.views.minimum_visible_percentage', 50)
            && $visibleSeconds >= (float) config('community.views.minimum_visible_seconds', 1.5);
    }
}
