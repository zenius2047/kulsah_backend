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
use App\Notifications\VideoMentionedNotification;
use App\Services\VideoInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
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
                'content_type' => ['dance', 'music'],
                'visibility' => 'public',
                'video' => UploadedFile::fake()->create('sample.mp4', 1024, 'video/mp4'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Video uploaded successfully and is being processed.');

        $videoId = $response->json('data.id');
        $video = Video::query()->findOrFail($videoId);

        $this->assertSame('dance', $video->content_type);
        $this->assertSame(['dance', 'music'], $video->content_types);
        $this->assertSame(25, $video->progress_percentage);
        $this->assertSame(0, $video->views_count);

        Queue::assertPushed(ProcessVideoJob::class);
    }

    public function test_creator_can_upload_video_first_and_update_metadata_later(): void
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
        Notification::fake();

        $creator = User::factory()->create([
            'name' => 'Creator Draft',
            'username' => 'creator_draft',
        ]);
        $mentioned = User::factory()->create([
            'name' => 'Mentioned Draft User',
            'username' => 'draft_user',
        ]);

        $uploadResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/creator/videos', [
                'video' => UploadedFile::fake()->create('draft.mp4', 1024, 'video/mp4'),
            ]);

        $uploadResponse->assertCreated()
            ->assertJsonPath('message', 'Video uploaded successfully and is being processed.')
            ->assertJsonPath('data.caption', null)
            ->assertJsonPath('data.content_type', null)
            ->assertJsonPath('data.visibility', 'public');

        $videoId = $uploadResponse->json('data.id');

        $updateResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->patchJson("/api/v1/creator/videos/{$videoId}", [
                'caption' => 'Updated caption for @draft_user',
                'content_type' => ['education', 'tutorial'],
                'visibility' => 'premium',
                'title' => 'My draft video',
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('message', 'Video updated successfully.')
            ->assertJsonPath('data.title', 'My draft video')
            ->assertJsonPath('data.caption', 'Updated caption for @draft_user')
            ->assertJsonPath('data.content_type', 'education')
            ->assertJsonPath('data.content_types.0', 'education')
            ->assertJsonPath('data.content_types.1', 'tutorial')
            ->assertJsonPath('data.visibility', 'premium');

        $this->assertDatabaseHas('videos', [
            'id' => $videoId,
            'title' => 'My draft video',
            'caption' => 'Updated caption for @draft_user',
            'content_type' => 'education',
            'visibility' => 'premium',
        ]);

        Notification::assertSentTo(
            $mentioned,
            VideoMentionedNotification::class,
            function (VideoMentionedNotification $notification) use ($videoId, $creator, $mentioned): bool {
                return (int) $notification->video->id === (int) $videoId
                    && (int) $notification->actor->id === (int) $creator->id
                    && in_array('draft_user', $notification->mentions, true);
            }
        );
    }

    public function test_creator_can_poll_video_upload_progress(): void
    {
        config()->set('logging.default', 'null');
        $creator = User::factory()->create([
            'username' => 'creator_progress',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Processing video',
            'caption' => 'Still uploading',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/example.mp4',
            'status' => 'processing',
            'progress_percentage' => 68,
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/videos/{$video->id}/progress");

        $response->assertOk()
            ->assertJsonPath('data.video_id', $video->id)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress_percentage', 68);
    }

    public function test_creator_upload_mentions_user_and_dispatches_notification(): void
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
        Notification::fake();

        $creator = User::factory()->create([
            'name' => 'Creator One',
            'username' => 'creator_1',
        ]);
        $mentioned = User::factory()->create([
            'name' => 'Mentioned User',
            'username' => 'mentioned_user',
        ]);

        $response = $this
            ->actingAs($creator)
            ->withoutMiddleware()
            ->postJson('/api/v1/creator/videos', [
                'title' => 'Mentions test',
                'caption' => 'A fun #dance clip with @mentioned_user',
                'content_type' => ['dance', 'music'],
                'visibility' => 'public',
                'video' => UploadedFile::fake()->create('sample.mp4', 1024, 'video/mp4'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.content_type', 'dance');
        $response->assertJsonPath('data.content_types.0', 'dance');
        $response->assertJsonPath('data.content_types.1', 'music');

        $videoId = $response->json('data.id');

        $this->assertDatabaseHas('videos', [
            'id' => $videoId,
            'content_type' => 'dance',
        ]);

        Notification::assertSentTo(
            $mentioned,
            VideoMentionedNotification::class,
            function (VideoMentionedNotification $notification) use ($videoId, $creator, $mentioned): bool {
                return (int) $notification->video->id === (int) $videoId
                    && (int) $notification->actor->id === (int) $creator->id
                    && in_array('dance', $notification->hashtags, true)
                    && in_array('mentioned_user', $notification->mentions, true);
            }
        );
    }

    public function test_user_can_record_a_video_view_once_during_cooldown(): void
    {
        config()->set('cache.default', 'array');

        $viewer = User::factory()->create([
            'username' => 'viewer_views',
        ]);
        $creator = User::factory()->create([
            'username' => 'creator_views',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Viewed video',
            'caption' => 'A clip',
            'content_type' => 'dance',
            'content_types' => ['dance'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/example.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/example.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/example.jpg',
            'duration' => 42,
            'status' => 'ready',
            'views_count' => 0,
            'metadata' => [],
        ]);

        $first = $this
            ->actingAs($viewer, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/general/videos/{$video->id}/view");

        $first->assertOk()
            ->assertJsonPath('data.views_count', 1);

        $second = $this
            ->actingAs($viewer, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/general/videos/{$video->id}/view");

        $second->assertOk()
            ->assertJsonPath('data.views_count', 1);
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
                'content_type' => ['education', 'tutorial'],
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
