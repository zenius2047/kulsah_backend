<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Jobs\ProcessVideoJob;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use App\Notifications\VideoMentionedNotification;
use App\Services\CloudinaryService;
use App\Services\VideoInspectionService;
use App\Services\VideoService;
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

    public function test_direct_upload_that_requires_editing_defers_cloudinary_processing(): void
    {
        Storage::fake('testlocal');
        config()->set('video.storage_disk', 'testlocal');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'edit_first_creator',
        ]);
        $sourceKey = "videos/originals/{$creator->id}/edit-first.mp4";
        Storage::disk('testlocal')->put($sourceKey, 'video-bytes');

        $video = Video::create([
            'user_id' => $creator->id,
            'source_key' => $sourceKey,
            'status' => 'draft',
            'metadata' => [
                'upload_mode' => 'direct',
                'upload_state' => 'awaiting_upload',
                'storage_disk' => 'testlocal',
                'requires_editing' => true,
            ],
        ]);

        $completed = app(VideoService::class)->finalizeDirectUpload($video, (int) $creator->id);

        $this->assertSame('draft', $completed->status);
        $this->assertSame('awaiting_edit', $completed->render_status);
        $this->assertSame(100, $completed->progress_percentage);
        $this->assertSame('uploaded', data_get($completed->metadata, 'upload_state'));
        $this->assertSame('awaiting_edit', data_get($completed->metadata, 'processing_state'));
        Queue::assertNotPushed(ProcessVideoJob::class);
    }

    public function test_direct_upload_without_editing_still_queues_cloudinary_processing(): void
    {
        Storage::fake('testlocal');
        config()->set('video.storage_disk', 'testlocal');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'direct_publish_creator',
        ]);
        $sourceKey = "videos/originals/{$creator->id}/publish-directly.mp4";
        Storage::disk('testlocal')->put($sourceKey, 'video-bytes');

        $video = Video::create([
            'user_id' => $creator->id,
            'source_key' => $sourceKey,
            'status' => 'draft',
            'metadata' => [
                'upload_mode' => 'direct',
                'upload_state' => 'awaiting_upload',
                'storage_disk' => 'testlocal',
                'requires_editing' => false,
            ],
        ]);

        $completed = app(VideoService::class)->finalizeDirectUpload($video, (int) $creator->id);

        $this->assertSame('uploaded', data_get($completed->metadata, 'upload_state'));
        $this->assertSame('queued', data_get($completed->metadata, 'processing_state'));
        Queue::assertPushed(ProcessVideoJob::class);
    }

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
                'thumbnail' => UploadedFile::fake()->image('sample-thumbnail.jpg', 720, 1280),
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Video uploaded successfully and is now in draft while processing starts.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.progress_percentage', 100);

        $videoId = $response->json('data.id');
        $video = Video::query()->findOrFail($videoId);

        $this->assertSame('dance', $video->content_type);
        $this->assertSame(['dance', 'music'], $video->content_types);
        $this->assertSame(100, $video->progress_percentage);
        $this->assertSame(0, $video->views_count);
        $this->assertNotNull($video->thumbnail_url);
        $this->assertStringStartsWith('http://localhost/storage/', $video->thumbnail_url);

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
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson('/api/v1/creator/videos', [
                'video' => UploadedFile::fake()->create('draft.mp4', 1024, 'video/mp4'),
            ]);

        $uploadResponse->assertCreated()
            ->assertJsonPath('message', 'Video uploaded successfully and is now in draft while processing starts.')
            ->assertJsonPath('data.caption', null)
            ->assertJsonPath('data.content_type', null)
            ->assertJsonPath('data.visibility', 'public');

        $videoId = $uploadResponse->json('data.id');

        $updateResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
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
            function (VideoMentionedNotification $notification) use ($videoId, $creator): bool {
                return (int) $notification->video->id === (int) $videoId
                    && (int) $notification->actor->id === (int) $creator->id
                    && in_array('draft_user', $notification->mentions, true);
            }
        );
    }

    public function test_creator_can_view_single_dashboard_video(): void
    {
        config()->set('logging.default', 'null');

        $creator = User::factory()->create([
            'name' => 'Dashboard Creator',
            'username' => 'dashboard_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Dashboard Item',
            'caption' => 'PRIVATE DROP: Working on Nebula vocal layers #BTS',
            'content_type' => 'music',
            'content_types' => ['music', 'performance'],
            'visibility' => 'premium',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/dashboard.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/demo-playback.m3u8',
            'streaming_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/demo-playback.m3u8',
            'thumbnail_url' => 'https://example.com/dashboard.jpg',
            'duration' => 125,
            'status' => 'ready',
            'views_count' => 2050,
            'likes_count' => 0,
            'metadata' => [],
        ]);

        Video::create([
            'user_id' => $creator->id,
            'title' => 'Other Creator Video',
            'caption' => 'Another clip',
            'content_type' => 'dance',
            'content_types' => ['dance'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/other-video.mp4',
            'source_key' => 'videos/originals/1/other-video.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/other-playback.m3u8',
            'streaming_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/other-playback.m3u8',
            'thumbnail_url' => 'https://example.com/other-video.jpg',
            'duration' => 45,
            'status' => 'ready',
            'views_count' => 12,
            'metadata' => [],
        ]);

        VideoLike::create([
            'video_id' => $video->id,
            'user_id' => $creator->id,
        ]);

        VideoComment::create([
            'video_id' => $video->id,
            'user_id' => $creator->id,
            'body' => 'First comment',
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson("/api/v1/creator/videos/{$video->id}");

        $response->assertOk()
            ->assertJsonPath('item.id', (string) $video->id)
            ->assertJsonPath('item.creator', 'Dashboard Creator')
            ->assertJsonPath('item.creator_id', (string) $creator->id)
            ->assertJsonPath('item.handle', '@dashboard_creator')
            ->assertJsonPath('item.avatar', null)
            ->assertJsonPath('item.caption', 'PRIVATE DROP: Working on Nebula vocal layers #BTS')
            ->assertJsonPath('item.background', 'https://example.com/dashboard.jpg')
            ->assertJsonPath('item.video', 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/demo-playback.m3u8')
            ->assertJsonPath('item.views', '2.1K')
            ->assertJsonPath('item.likes', '1')
            ->assertJsonPath('item.comments_count', '1')
            ->assertJsonCount(1, 'item.comments')
            ->assertJsonCount(1, 'item.otherVideos');

        $this->assertSame('First comment', $response->json('item.comments.0.text'));
        $this->assertSame((string) $creator->id, $response->json('item.otherVideos.0.creator_id'));
        $this->assertSame('Dashboard Creator', $response->json('item.otherVideos.0.creator'));
        $this->assertSame('@dashboard_creator', $response->json('item.otherVideos.0.handle'));
        $this->assertSame('Another clip', $response->json('item.otherVideos.0.caption'));
        $this->assertSame('https://example.com/other-video.jpg', $response->json('item.otherVideos.0.background'));
        $this->assertSame('https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/other-playback.m3u8', $response->json('item.otherVideos.0.video'));
        $this->assertSame('12', $response->json('item.otherVideos.0.views'));
        $this->assertSame('0', $response->json('item.otherVideos.0.likes'));
        $this->assertSame('0', $response->json('item.otherVideos.0.comments_count'));
        $response->assertJsonCount(0, 'item.otherVideos.0.comments');
    }

    public function test_creator_video_list_supports_filters(): void
    {
        config()->set('logging.default', 'null');

        $creator = User::factory()->create([
            'username' => 'filter_creator',
        ]);
        $otherCreator = User::factory()->create([
            'username' => 'filter_other_creator',
        ]);

        $draftPremium = Video::create([
            'user_id' => $creator->id,
            'title' => 'Draft Premium',
            'caption' => 'Draft premium caption',
            'content_type' => 'dance',
            'content_types' => ['dance'],
            'visibility' => 'premium',
            'source_url' => 'https://example.com/draft-premium.mp4',
            'source_key' => 'videos/originals/1/draft-premium.mp4',
            'thumbnail_url' => 'https://example.com/draft-premium.jpg',
            'duration' => 61,
            'status' => 'processing',
            'views_count' => 10,
            'metadata' => [],
        ]);

        $readyMusic = Video::create([
            'user_id' => $creator->id,
            'title' => 'Ready Music',
            'caption' => 'Ready music caption',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/ready-music.mp4',
            'source_key' => 'videos/originals/1/ready-music.mp4',
            'thumbnail_url' => 'https://example.com/ready-music.jpg',
            'duration' => 40,
            'status' => 'ready',
            'views_count' => 30,
            'metadata' => [],
        ]);

        Video::create([
            'user_id' => $otherCreator->id,
            'title' => 'Other Creator Video',
            'caption' => 'Not mine',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'premium',
            'source_url' => 'https://example.com/other.mp4',
            'source_key' => 'videos/originals/1/other.mp4',
            'thumbnail_url' => 'https://example.com/other.jpg',
            'duration' => 33,
            'status' => 'ready',
            'views_count' => 1,
            'metadata' => [],
        ]);

        $allResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/videos');

        $allResponse->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.views', '30')
            ->assertJsonPath('data.1.views', '10');

        $draftOnlyResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/videos?draft=true');

        $draftOnlyResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $draftPremium->id)
            ->assertJsonPath('data.0.draft', true)
            ->assertJsonPath('data.0.views', '10');

        $premiumResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/videos?premium=true');

        $premiumResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $draftPremium->id)
            ->assertJsonPath('data.0.premium', true);

        $categoryResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/videos?category=music');

        $categoryResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $readyMusic->id)
            ->assertJsonPath('data.0.category', 'music')
            ->assertJsonPath('data.0.views', '30');
    }

    public function test_creator_analytics_returns_counts_and_totals(): void
    {
        config()->set('logging.default', 'null');

        $creator = User::factory()->create([
            'username' => 'analytics_creator',
        ]);

        $first = Video::create([
            'user_id' => $creator->id,
            'title' => 'Analytics One',
            'caption' => 'First analytics video',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/analytics-1.mp4',
            'source_key' => 'videos/originals/1/analytics-1.mp4',
            'thumbnail_url' => 'https://example.com/analytics-1.jpg',
            'duration' => 90,
            'status' => 'ready',
            'views_count' => 100,
            'metadata' => [],
        ]);

        $second = Video::create([
            'user_id' => $creator->id,
            'title' => 'Analytics Two',
            'caption' => 'Second analytics video',
            'content_type' => 'dance',
            'content_types' => ['dance'],
            'visibility' => 'premium',
            'source_url' => 'https://example.com/analytics-2.mp4',
            'source_key' => 'videos/originals/1/analytics-2.mp4',
            'thumbnail_url' => 'https://example.com/analytics-2.jpg',
            'duration' => 150,
            'status' => 'processing',
            'views_count' => 50,
            'metadata' => [],
        ]);

        VideoLike::create([
            'video_id' => $first->id,
            'user_id' => $creator->id,
        ]);

        VideoLike::create([
            'video_id' => $second->id,
            'user_id' => $creator->id,
        ]);

        VideoComment::create([
            'video_id' => $first->id,
            'user_id' => $creator->id,
            'body' => 'Nice one',
        ]);

        VideoComment::create([
            'video_id' => $second->id,
            'user_id' => $creator->id,
            'body' => 'Great one',
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/videos/analytics');

        $response->assertOk()
            ->assertJsonPath('data.total_videos', 2)
            ->assertJsonPath('data.ready_videos', 1)
            ->assertJsonPath('data.draft_videos', 1)
            ->assertJsonPath('data.premium_videos', 1)
            ->assertJsonPath('data.public_videos', 1)
            ->assertJsonPath('data.processing_videos', 1)
            ->assertJsonPath('data.failed_videos', 0)
            ->assertJsonPath('data.total_views', 150)
            ->assertJsonPath('data.total_likes', 2)
            ->assertJsonPath('data.total_comments', 2)
            ->assertJsonPath('data.total_duration_seconds', 240)
            ->assertJsonPath('data.total_duration', '04:00')
            ->assertJsonPath('data.average_views', 75);
    }

    public function test_creator_can_poll_video_upload_progress(): void
    {
        config()->set('logging.default', 'null');
        $creator = User::factory()->create([
            'username' => 'creator_progress',
        ]);

        $draftResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson('/api/v1/creator/videos/drafts', [
                'title' => 'Processing video',
                'caption' => 'Still uploading',
                'visibility' => 'public',
            ]);

        $draftResponse->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.progress_percentage', 0);

        $videoId = $draftResponse->json('data.id');

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->patchJson("/api/v1/creator/videos/{$videoId}/progress", [
                'progress_percentage' => 68,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Video upload progress updated successfully.')
            ->assertJsonPath('data.video_id', $videoId)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.progress_percentage', 68);

        $polled = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson("/api/v1/creator/videos/{$videoId}/progress");

        $polled->assertOk()
            ->assertJsonPath('data.video_id', $videoId)
            ->assertJsonPath('data.status', 'draft')
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
                'thumbnail' => UploadedFile::fake()->image('mentions-thumbnail.png', 720, 1280),
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
            function (VideoMentionedNotification $notification) use ($videoId, $creator): bool {
                return (int) $notification->video->id === (int) $videoId
                    && (int) $notification->actor->id === (int) $creator->id
                    && in_array('dance', $notification->hashtags, true)
                    && in_array('mentioned_user', $notification->mentions, true);
            }
        );
    }

    public function test_video_processing_keeps_a_creator_uploaded_thumbnail(): void
    {
        config()->set('logging.default', 'null');

        $creator = User::factory()->create([
            'username' => 'creator_thumbnail',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Thumbnail test',
            'caption' => 'Keeping my own cover image',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/thumbnail-test.mp4',
            'thumbnail_url' => 'https://example.com/custom-thumbnail.jpg',
            'duration' => 18,
            'status' => 'processing',
            'metadata' => [],
        ]);

        $this->mock(CloudinaryService::class, function ($mock): void {
            $mock->shouldReceive('uploadVideoFromS3Key')
                ->once()
                ->andReturn([
                    'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/demo-playback.m3u8',
                    'stream_url' => 'https://res.cloudinary.com/demo/video/upload/demo-playback.m3u8',
                    'cloudinary_public_id' => 'demo-playback',
                    'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/generated-thumbnail.jpg',
                    'duration' => 18,
                    'streaming_profile' => '2160p',
                    'metadata' => [],
                ]);
        });

        (new ProcessVideoJob($video))->handle(app(CloudinaryService::class));

        $video->refresh();

        $this->assertSame('https://example.com/custom-thumbnail.jpg', $video->thumbnail_url);
        $this->assertSame('https://res.cloudinary.com/demo/video/upload/demo-playback.m3u8', $video->cdn_url);
        $this->assertSame('demo-playback', $video->cloudinary_public_id);
        $this->assertSame('ready', $video->status);
    }

    public function test_original_processing_job_skips_an_edit_first_video(): void
    {
        $creator = User::factory()->create([
            'username' => 'edit_first_job_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'source_key' => "videos/originals/{$creator->id}/edit-first-job.mp4",
            'status' => 'draft',
            'render_status' => 'awaiting_edit',
            'metadata' => [
                'requires_editing' => true,
                'upload_state' => 'uploaded',
            ],
        ]);

        $this->mock(CloudinaryService::class, function ($mock): void {
            $mock->shouldNotReceive('uploadVideoFromS3Key');
        });

        (new ProcessVideoJob($video))->handle(app(CloudinaryService::class));

        $video->refresh();

        $this->assertSame('draft', $video->status);
        $this->assertSame('awaiting_edit', $video->render_status);
        $this->assertNull($video->cloudinary_public_id);
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
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson("/api/v1/general/videos/{$video->id}/view");

        $first->assertOk()
            ->assertJsonPath('data.views_count', 1);

        $second = $this
            ->actingAs($viewer, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
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
