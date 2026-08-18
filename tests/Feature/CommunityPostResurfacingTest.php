<?php

namespace Tests\Feature;

use App\Models\CommunityPost;
use App\Models\CommunityPostLike;
use App\Models\CommunityPostView;
use App\Models\User;
use App\Services\CommunityFeedSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CommunityPostResurfacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_view_history_is_aggregated_and_short_interval_replays_do_not_inflate_public_counts(): void
    {
        $viewer = User::factory()->create();
        $post = $this->createPost('video');

        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$post->id.'/view', [
            'watch_duration_seconds' => 10,
            'completion_percentage' => 25,
        ])->assertOk()
            ->assertJsonPath('meta.meaningful', true)
            ->assertJsonPath('meta.counted', true);

        $this->travel(1)->second();
        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$post->id.'/view', [
            'watch_duration_seconds' => 20,
            'completion_percentage' => 90,
        ])->assertOk()
            ->assertJsonPath('meta.counted', false);

        $history = CommunityPostView::query()->firstOrFail();
        $this->assertSame(1, $history->view_count);
        $this->assertSame(90.0, (float) $history->max_completion_percentage);
        $this->assertTrue($history->reached_25_percent);
        $this->assertTrue($history->reached_50_percent);
        $this->assertTrue($history->reached_75_percent);
        $this->assertTrue($history->reached_90_percent);
        $this->assertSame(1, $post->fresh()->views_count);

        $this->travel(31)->seconds();
        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$post->id.'/view', [
            'watch_duration_seconds' => 5,
            'completion_percentage' => 100,
            'completed' => true,
        ])->assertOk()->assertJsonPath('meta.counted', true);

        $history->refresh();
        $this->assertSame(2, $history->view_count);
        $this->assertSame(1, $history->completed_count);
        $this->assertSame(15.0, (float) $history->watch_duration_seconds);
        $this->assertSame(2, $post->fresh()->views_count);
    }

    public function test_brief_visibility_does_not_create_meaningful_text_history(): void
    {
        $viewer = User::factory()->create();
        $post = $this->createPost();

        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$post->id.'/view', [
            'visible_percentage' => 30,
            'visible_duration_seconds' => 0.5,
        ])->assertOk()
            ->assertJsonPath('meta.meaningful', false)
            ->assertJsonPath('meta.counted', false);

        $this->assertDatabaseCount('community_post_views', 0);
        $this->assertSame(0, $post->fresh()->views_count);
    }

    public function test_view_payload_is_validated_and_inaccessible_posts_are_rejected(): void
    {
        $viewer = User::factory()->create();
        $video = $this->createPost('video');
        $privatePost = $this->createPost(audience: 'subscribers');

        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$video->id.'/view', [
            'watch_duration_seconds' => -1,
            'completion_percentage' => 101,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['watch_duration_seconds', 'completion_percentage']);

        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$privatePost->id.'/view', [
            'visible_percentage' => 100,
            'visible_duration_seconds' => 2,
        ])->assertForbidden();
    }

    public function test_viewed_post_remains_feed_eligible_and_exposes_private_viewer_state(): void
    {
        $viewer = User::factory()->create();
        $post = $this->createPost();

        $this->actingAs($viewer)->withoutMiddleware()->postJson('/api/v1/general/community/posts/'.$post->id.'/view', [
            'visible_percentage' => 100,
            'visible_duration_seconds' => 2,
        ])->assertOk();

        $this->actingAs($viewer)->withoutMiddleware()->getJson('/api/v1/general/community/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.viewer.has_viewed', true)
            ->assertJsonPath('data.0.viewer.view_count', 1);
    }

    public function test_recently_served_post_is_not_repeated_immediately(): void
    {
        $viewer = User::factory()->create();
        $this->createPost(content: 'First');
        $this->createPost(content: 'Second');

        $firstId = $this->actingAs($viewer)->withoutMiddleware()
            ->getJson('/api/v1/general/community/posts?per_page=1')
            ->assertOk()
            ->json('data.0.id');
        $secondId = $this->actingAs($viewer)->withoutMiddleware()
            ->getJson('/api/v1/general/community/posts?per_page=1&page=2')
            ->assertOk()
            ->json('data.0.id');

        $this->assertNotSame($firstId, $secondId);
    }

    public function test_old_and_partially_watched_video_can_resurface(): void
    {
        $viewer = User::factory()->create();
        $post = $this->createPost('video');
        CommunityPostView::query()->create([
            'user_id' => $viewer->id,
            'community_post_id' => $post->id,
            'first_viewed_at' => now()->subDays(4),
            'last_viewed_at' => now()->subDays(4),
            'last_counted_at' => now()->subDays(4),
            'view_count' => 1,
            'watch_duration_seconds' => 2,
            'last_watch_duration_seconds' => 2,
            'completion_percentage' => 10,
            'max_completion_percentage' => 10,
        ]);

        $this->actingAs($viewer)->withoutMiddleware()->getJson('/api/v1/general/community/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.viewer.completion_percentage', 10);
    }

    public function test_new_activity_and_prior_engagement_allow_viewed_posts_to_resurface(): void
    {
        $viewer = User::factory()->create();
        $viewed = $this->createPost(content: 'Viewed with new activity');
        $unseen = $this->createPost(content: 'Unseen');
        $history = CommunityPostView::query()->create([
            'user_id' => $viewer->id,
            'community_post_id' => $viewed->id,
            'first_viewed_at' => now()->subHours(30),
            'last_viewed_at' => now()->subHours(30),
            'last_counted_at' => now()->subHours(30),
            'view_count' => 1,
            'completion_percentage' => 100,
            'max_completion_percentage' => 100,
        ]);
        CommunityPostLike::query()->create([
            'user_id' => $viewer->id,
            'community_post_id' => $viewed->id,
        ]);
        $viewed->update(['last_activity_at' => now()]);

        app(CommunityFeedSessionService::class)->clear((int) $viewer->id);
        $response = $this->actingAs($viewer)->withoutMiddleware()->getJson('/api/v1/general/community/posts?per_page=2');
        $response->assertOk()
            ->assertJsonPath('data.0.id', $viewed->id)
            ->assertJsonPath('data.0.viewer.liked', true);
        $this->assertTrue($viewed->fresh()->last_activity_at->gt($history->last_viewed_at));
        $this->assertContains($unseen->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_history_is_private_ordered_and_filterable(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $text = $this->createPost('text', 'Text history');
        $video = $this->createPost('video', 'Video history');
        $otherPost = $this->createPost('text', 'Other history');

        $this->history($viewer, $text, now()->subDay(), 100);
        $this->history($viewer, $video, now()->subHour(), 40);
        $this->history($other, $otherPost, now(), 100);

        $this->actingAs($viewer)->withoutMiddleware()->getJson('/api/v1/general/community/history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.1.id', $text->id)
            ->assertJsonMissing(['id' => $otherPost->id]);

        $this->actingAs($viewer)->withoutMiddleware()->getJson('/api/v1/general/community/history?type=video')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id);
    }

    private function createPost(string $type = 'text', string $content = 'Community post', string $audience = 'public'): CommunityPost
    {
        return CommunityPost::query()->create([
            'user_id' => User::factory()->create()->id,
            'type' => $type,
            'content' => $content,
            'audience' => $audience,
            'status' => 'published',
            'views_count' => 0,
            'media_ids' => [],
            'poll' => null,
        ]);
    }

    private function history(User $viewer, CommunityPost $post, $viewedAt, float $completion): CommunityPostView
    {
        return CommunityPostView::query()->create([
            'user_id' => $viewer->id,
            'community_post_id' => $post->id,
            'first_viewed_at' => $viewedAt,
            'last_viewed_at' => $viewedAt,
            'last_counted_at' => $viewedAt,
            'view_count' => 1,
            'completion_percentage' => $completion,
            'max_completion_percentage' => $completion,
        ]);
    }
}
