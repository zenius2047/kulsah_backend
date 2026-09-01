<?php

namespace Tests\Feature;

use App\Domain\Challenges\Actions\CastChallengeBallot;
use App\Domain\Challenges\Actions\CreateChallenge;
use App\Domain\Challenges\Services\ChallengeLeaderboardService;
use App\Domain\Challenges\Services\ChallengeLifecycleService;
use App\Enums\ChallengeStatus;
use App\Http\Resources\VideoResource;
use App\Jobs\ProcessVideoJob;
use App\Models\Challenge;
use App\Models\ChallengeEntry;
use App\Models\User;
use App\Models\Video;
use App\Services\CloudinaryService;
use App\Services\VideoService;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class VideoUploadArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_initializes_private_primary_storage_upload(): void
    {
        $creator = User::factory()->create();
        $this->mock(VideoStorageService::class, function ($mock) use ($creator): void {
            $mock->shouldReceive('createTemporaryUpload')->once()
                ->with((int) $creator->id, 'challenge.mp4', 'video/mp4')
                ->andReturn([
                    'disk' => 's3',
                    'source_key' => "videos/originals/{$creator->id}/generated.mp4",
                    'source_url' => 'https://private-storage.example/raw.mp4',
                    'upload_url' => 'https://signed-storage.example/upload',
                    'upload_headers' => ['Content-Type' => 'video/mp4'],
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                ]);
        });

        $response = $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/media/video-uploads', [
            'filename' => 'challenge.mp4', 'mimeType' => 'video/mp4', 'fileSize' => 4096,
            'purpose' => 'challenge_video',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'initialized')
            ->assertJsonPath('data.upload.method', 'PUT')
            ->assertJsonPath('data.upload.url', 'https://signed-storage.example/upload')
            ->assertJsonMissing(['source_url' => 'https://private-storage.example/raw.mp4']);
        $video = Video::findOrFail($response->json('data.videoId'));
        $this->assertStringStartsWith("videos/originals/{$creator->id}/", $video->source_key);
        $this->assertSame('challenge_video', $video->purpose->value);
    }

    public function test_upload_initialization_rejects_invalid_mime_and_size(): void
    {
        $creator = User::factory()->create();
        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/media/video-uploads', [
            'filename' => 'malware.exe', 'mimeType' => 'application/octet-stream', 'fileSize' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('mime_type');

        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/media/video-uploads', [
            'filename' => 'empty.mp4', 'mimeType' => 'video/mp4', 'fileSize' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('size');
    }

    public function test_completion_verifies_object_and_is_idempotent(): void
    {
        Storage::fake('video-test');
        config()->set('video.storage_disk', 'video-test');
        Queue::fake();
        $creator = User::factory()->create();
        $key = "videos/originals/{$creator->id}/source.mp4";
        Storage::disk('video-test')->put($key, 'raw-video');
        $video = Video::create([
            'user_id' => $creator->id, 'source_key' => $key, 'source_disk' => 'video-test',
            'file_size' => 9, 'mime_type' => 'video/mp4', 'status' => 'draft',
            'upload_status' => 'initialized', 'processing_status' => 'initialized',
        ]);

        $first = app(VideoService::class)->finalizeDirectUpload($video, $creator->id);
        $second = app(VideoService::class)->finalizeDirectUpload($video, $creator->id);

        $this->assertSame('uploaded', $first->upload_status->value);
        $this->assertSame('queued', $second->processing_status->value);
        Queue::assertPushed(ProcessVideoJob::class, 1);
    }

    public function test_nonexistent_primary_object_cannot_be_completed(): void
    {
        Storage::fake('video-test');
        $creator = User::factory()->create();
        $video = Video::create([
            'user_id' => $creator->id, 'source_key' => "videos/originals/{$creator->id}/missing.mp4",
            'source_disk' => 'video-test', 'status' => 'draft', 'upload_status' => 'initialized',
            'processing_status' => 'initialized',
        ]);
        $this->expectException(ValidationException::class);
        app(VideoService::class)->finalizeDirectUpload($video, $creator->id);
    }

    public function test_processing_reads_original_and_persists_hls_playback_idempotently(): void
    {
        Storage::fake('video-test');
        $creator = User::factory()->create();
        $key = "videos/originals/{$creator->id}/process.mp4";
        Storage::disk('video-test')->put($key, 'raw-video');
        $video = Video::create([
            'user_id' => $creator->id, 'source_key' => $key, 'source_disk' => 'video-test',
            'status' => 'draft', 'upload_status' => 'uploaded', 'processing_status' => 'queued',
        ]);
        $cloudinary = $this->mock(CloudinaryService::class, function ($mock) use ($key): void {
            $mock->shouldReceive('uploadVideoFromS3Key')->once()->with($key, 'video-test')->andReturn([
                'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto/demo.m3u8',
                'streaming_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto/demo.m3u8',
                'rendered_url' => 'https://res.cloudinary.com/demo/video/upload/demo.mp4',
                'cloudinary_public_id' => 'kulsah/demo', 'cloudinary_asset_id' => 'asset-1',
                'poster_url' => 'https://res.cloudinary.com/demo/video/upload/demo.jpg',
                'duration' => 28, 'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16',
                'metadata' => [],
            ]);
        });

        (new ProcessVideoJob($video))->handle($cloudinary);
        (new ProcessVideoJob($video))->handle($cloudinary);
        $video->refresh();

        $this->assertSame('ready', $video->processing_status->value);
        $this->assertSame('hls', $video->playback_type);
        $this->assertStringEndsWith('.m3u8', $video->hls_url);
        $this->assertSame(28000, $video->duration_ms);
        $this->assertTrue(Storage::disk('video-test')->exists($key));
    }

    public function test_processing_failure_is_retryable_from_original_source(): void
    {
        Storage::fake('video-test');
        Queue::fake();
        $creator = User::factory()->create();
        $key = "videos/originals/{$creator->id}/retry.mp4";
        Storage::disk('video-test')->put($key, 'raw-video');
        $video = Video::create([
            'user_id' => $creator->id, 'source_key' => $key, 'source_disk' => 'video-test',
            'status' => 'draft', 'upload_status' => 'uploaded', 'processing_status' => 'queued',
        ]);
        $cloudinary = $this->mock(CloudinaryService::class, fn ($mock) => $mock->shouldReceive('uploadVideoFromS3Key')->once()->andThrow(new RuntimeException('provider unavailable')));
        try {
            (new ProcessVideoJob($video))->handle($cloudinary);
            $this->fail('Processing should have failed.');
        } catch (RuntimeException) {
        }
        $this->assertSame('processing_failed', $video->refresh()->processing_status->value);
        $retried = app(VideoService::class)->retryProcessing($video, $creator->id);
        $this->assertSame('queued', $retried->processing_status->value);
        Queue::assertPushed(ProcessVideoJob::class);
    }

    public function test_challenge_draft_accepts_processing_media_but_publication_waits_for_hls(): void
    {
        $creator = User::factory()->create();
        $video = Video::factory()->create([
            'user_id' => $creator->id, 'status' => 'processing', 'processing_status' => 'processing',
            'hls_url' => null, 'streaming_url' => null,
        ]);
        $payload = $this->challengePayload($video->id);
        $challenge = app(CreateChallenge::class)->execute($creator, $payload);
        $lifecycle = app(ChallengeLifecycleService::class);
        $lifecycle->transition($challenge, ChallengeStatus::PendingReview);

        try {
            $lifecycle->transition($challenge, ChallengeStatus::Active);
            $this->fail('Challenge publication should have been blocked.');
        } catch (ValidationException) {
        }
        $video->update(['status' => 'ready', 'processing_status' => 'ready', 'hls_url' => 'https://cdn.example/video.m3u8']);
        $published = $lifecycle->transition($challenge, ChallengeStatus::Active);
        $this->assertSame(ChallengeStatus::Active, $published->status);
    }

    public function test_challenge_api_rejects_local_file_uri_and_unknown_media(): void
    {
        $creator = User::factory()->create();
        $video = Video::factory()->create(['user_id' => $creator->id]);
        $payload = $this->challengePayload($video->id);
        $payload['media'][0]['metadata'] = ['uri' => 'file:///selected-cover.jpg'];

        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('media.0.metadata.uri');

        $payload = $this->challengePayload(99999999);
        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('media.0.video_id');
    }

    public function test_non_ready_entry_is_excluded_from_leaderboard_and_voting(): void
    {
        $host = User::factory()->create();
        $entrant = User::factory()->create();
        $voter = User::factory()->create();
        $challenge = Challenge::factory()->active()->create([
            'created_by_user_id' => $host->id, 'host_user_id' => $host->id,
            'voting_starts_at' => now()->subMinute(), 'voting_ends_at' => now()->addHour(),
            'voting_configuration' => ['mode' => 'single_choice'],
        ]);
        $video = Video::factory()->create(['user_id' => $entrant->id, 'status' => 'failed', 'processing_status' => 'processing_failed', 'hls_url' => null]);
        $entry = ChallengeEntry::factory()->create(['challenge_id' => $challenge->id, 'creator_id' => $entrant->id, 'video_id' => $video->id]);

        $this->assertSame(0, app(ChallengeLeaderboardService::class)->get($challenge)->total());
        $this->expectException(ValidationException::class);
        app(CastChallengeBallot::class)->execute($challenge, $voter, [['challenge_entry_id' => $entry->id]]);
    }

    public function test_video_resource_prefers_hls_and_never_exposes_raw_source(): void
    {
        $video = Video::factory()->make([
            'source_url' => 'https://private-storage.example/raw.mp4',
            'source_key' => 'videos/originals/1/raw.mp4',
            'metadata' => ['storage_disk' => 's3', 'source_key' => 'videos/originals/1/raw.mp4'],
        ]);
        $data = VideoResource::make($video)->toArray(Request::create('/'));
        $this->assertSame($video->hls_url, $data['playback']['url']);
        $this->assertSame('hls', $data['playback']['type']);
        $this->assertArrayNotHasKey('source_url', $data);
        $this->assertArrayNotHasKey('source_key', $data['metadata']);
    }

    private function challengePayload(int $videoId): array
    {
        return [
            'title' => 'Video challenge', 'description' => 'Create a processed video.',
            'visibility' => 'public', 'judging_strategy' => 'points',
            'winner_selection_method' => 'automatic_score', 'submission_starts_at' => now()->subHour(),
            'submission_ends_at' => now()->addWeek(), 'max_entries_per_creator' => 1,
            'media' => [['video_id' => $videoId, 'role' => 'challenge_video']],
            'prizes' => [['rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'feature', 'title' => 'Featured']],
            'scoring_components' => [['type' => 'reactions', 'weight_bps' => 0, 'point_value' => 1]],
        ];
    }
}
