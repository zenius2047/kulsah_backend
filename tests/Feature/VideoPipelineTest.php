<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use App\Models\Video;
use App\Services\VideoInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_upload_video_and_queue_processing(): void
    {
        config()->set('logging.default', 'null');
        $diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kulsah-video-tests';
        File::ensureDirectoryExists($diskRoot);

        config()->set('filesystems.disks.testlocal', [
            'driver' => 'local',
            'root' => $diskRoot,
            'url' => 'http://localhost/storage',
            'visibility' => 'private',
            'throw' => false,
        ]);
        config()->set('video.storage_disk', 'testlocal');
        app()->instance(VideoInspectionService::class, new class extends VideoInspectionService
        {
            public function getDurationSeconds(string $path): ?float
            {
                return 15.0;
            }
        });
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'creator_1',
        ]);

        $response = $this
            ->actingAs($creator)
            ->withoutMiddleware()
            ->postJson('/api/v1/creator/videos', [
                'title' => 'Test video',
                'caption' => 'Test caption',
                'visibility' => 'public',
                'video' => UploadedFile::fake()->create('sample.mp4', 1024, 'video/mp4'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Video uploaded successfully and is being processed.');

        $this->assertDatabaseHas('videos', [
            'user_id' => $creator->id,
            'title' => 'Test video',
            'status' => 'processing',
        ]);

        Queue::assertPushed(ProcessVideoJob::class);
    }

    public function test_creator_cannot_upload_video_longer_than_two_minutes(): void
    {
        config()->set('logging.default', 'null');
        $diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kulsah-video-tests';
        File::ensureDirectoryExists($diskRoot);

        config()->set('filesystems.disks.testlocal', [
            'driver' => 'local',
            'root' => $diskRoot,
            'url' => 'http://localhost/storage',
            'visibility' => 'private',
            'throw' => false,
        ]);
        config()->set('video.storage_disk', 'testlocal');
        app()->instance(VideoInspectionService::class, new class extends VideoInspectionService
        {
            public function getDurationSeconds(string $path): ?float
            {
                return 180.0;
            }
        });
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'creator_2',
        ]);

        $response = $this
            ->actingAs($creator)
            ->withoutMiddleware()
            ->postJson('/api/v1/creator/videos', [
                'title' => 'Too long video',
                'caption' => 'This should fail',
                'visibility' => 'public',
                'video' => UploadedFile::fake()->create('too-long.mp4', 1024, 'video/mp4'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        $this->assertDatabaseMissing('videos', [
            'user_id' => $creator->id,
            'title' => 'Too long video',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_feed_returns_ready_videos_and_caches_the_result(): void
    {
        config()->set('cache.default', 'array');
        config()->set('logging.default', 'null');
        Cache::flush();

        $viewer = User::factory()->create([
            'name' => 'Viewer One',
            'username' => 'viewer_1',
        ]);
        $creator = User::factory()->create([
            'name' => 'Creator One',
            'username' => 'creator_1',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Ready video',
            'caption' => 'A live clip',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/example.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/example.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/example.jpg',
            'duration' => 42,
            'status' => 'ready',
            'metadata' => ['source' => 'seed'],
        ]);

        VideoLike::create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        VideoBookmark::create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        VideoComment::create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'body' => 'Nice clip',
        ]);

        UserFollow::create([
            'follower_id' => $viewer->id,
            'followed_id' => $creator->id,
        ]);

        Subscription::create([
            'subscriber_id' => $viewer->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=10');

        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $video->id)
            ->assertJsonPath('data.0.creator', 'Creator One')
            ->assertJsonPath('data.0.handle', 'creator_1')
            ->assertJsonPath('data.0.video', $video->cdn_url)
            ->assertJsonPath('data.0.background', $video->thumbnail_url)
            ->assertJsonPath('data.0.likes', '1')
            ->assertJsonPath('data.0.comments', '1')
            ->assertJsonPath('data.0.isLiked', true)
            ->assertJsonPath('data.0.isSubscribed', true)
            ->assertJsonPath('data.0.following', true)
            ->assertJsonPath('data.0.bookmarks', '1')
            ->assertJsonPath('data.0.saves', '1')
            ->assertJsonPath('meta.cache_hit', false);

        $cachedResponse = $this
            ->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=10');

        $cachedResponse->assertOk()
            ->assertJsonPath('meta.cache_hit', true);
    }
}
