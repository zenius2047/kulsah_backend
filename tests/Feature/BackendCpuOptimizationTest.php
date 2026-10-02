<?php

namespace Tests\Feature;

use App\Jobs\RecordRecommendationEvent;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use App\Services\FeedService;
use App\Services\VideoCacheService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackendCpuOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_engagement_updates_counters_without_invalidating_every_feed(): void
    {
        Queue::fake();
        Cache::flush();

        $viewer = User::factory()->create();
        $video = $this->readyVideo(User::factory()->create());
        $feedVersion = app(FeedService::class)->currentFeedCacheVersion();
        $viewerVersion = app(VideoCacheService::class)->viewerVersion($viewer->id);

        $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/videos/{$video->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1);

        $this->assertSame(1, $video->refresh()->likes_count);
        $this->assertSame($feedVersion, app(FeedService::class)->currentFeedCacheVersion());
        $this->assertGreaterThan($viewerVersion, app(VideoCacheService::class)->viewerVersion($viewer->id));
        Queue::assertPushed(RecordRecommendationEvent::class);

        VideoBookmark::create(['video_id' => $video->id, 'user_id' => $viewer->id]);
        VideoComment::create(['video_id' => $video->id, 'user_id' => $viewer->id, 'body' => 'Efficient']);
        $this->assertSame(1, $video->refresh()->bookmarks_count);
        $this->assertSame(1, $video->comments_count);

        VideoLike::query()->where('video_id', $video->id)->where('user_id', $viewer->id)->firstOrFail()->delete();
        $this->assertSame(0, $video->refresh()->likes_count);
    }

    public function test_cached_feed_hit_skips_personalization_history_queries(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', false);
        Cache::flush();

        $viewer = User::factory()->create();
        $this->readyVideo(User::factory()->create());

        $this->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=10')
            ->assertOk();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=10')
            ->assertOk()
            ->assertJsonPath('meta.cache_hit', true);

        $sql = implode("\n", $queries);
        $this->assertStringNotContainsString('video_likes', $sql);
        $this->assertStringNotContainsString('video_bookmarks', $sql);
        $this->assertStringNotContainsString('video_views', $sql);
        $this->assertStringNotContainsString('subscriptions', $sql);
        $this->assertStringNotContainsString('user_follows', $sql);
    }

    private function readyVideo(User $creator): Video
    {
        return Video::factory()->create([
            'user_id' => $creator->id,
            'visibility' => 'public',
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
        ]);
    }
}
