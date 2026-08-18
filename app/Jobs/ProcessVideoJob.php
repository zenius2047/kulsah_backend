<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\CloudinaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 60, 120];

    public function __construct(public Video $video) {}

    public function handle(CloudinaryService $cloudinaryService): void
    {
        $video = $this->video->fresh();

        if (! $video) {
            Log::warning('ProcessVideoJob skipped because the video record no longer exists.', [
                'video_id' => $this->video->id ?? null,
            ]);

            return;
        }

        Log::info('ProcessVideoJob started.', [
            'video_id' => $video->id,
            'status' => $video->status,
            'source_key' => $video->source_key,
        ]);

        if ((bool) data_get($video->metadata, 'requires_editing', false)) {
            Log::info('ProcessVideoJob skipped because the source must be edited before Cloudinary publication.', [
                'video_id' => $video->id,
                'source_key' => $video->source_key,
            ]);

            return;
        }

        if ($video->processing_status?->value === 'ready' && $video->hls_url && $video->cloudinary_public_id) {
            Log::info('ProcessVideoJob skipped because the video is already ready.', [
                'video_id' => $video->id,
            ]);

            return;
        }

        if (! $video->source_key) {
            $video->update([
                'status' => 'failed',
                'processing_status' => 'processing_failed',
                'processing_error' => 'Missing source_key for Cloudinary processing.',
                'failed_at' => now(),
                'metadata' => array_merge($video->metadata ?? [], [
                    'error' => 'Missing source_key for Cloudinary processing.',
                ]),
            ]);

            Log::error('ProcessVideoJob failed because source_key is missing.', [
                'video_id' => $video->id,
            ]);

            throw new RuntimeException('Video source_key is missing.');
        }

        try {
            $disk = $video->source_disk ?: data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));
            if (! Storage::disk($disk)->exists($video->source_key)) {
                throw new RuntimeException('The original video is missing from primary storage.');
            }

            $claimed = Video::query()->whereKey($video->id)
                ->where('processing_status', '!=', 'ready')
                ->where(function ($query): void {
                    $query->where('processing_status', '!=', 'processing')
                        ->orWhereNull('processing_started_at')
                        ->orWhere('processing_started_at', '<', now()->subMinutes(10));
                })->update([
                    'status' => 'processing',
                    'processing_status' => 'processing',
                    'processing_started_at' => now(),
                    'processing_error' => null,
                    'failed_at' => null,
                ]);
            if ($claimed === 0) {
                Log::info('ProcessVideoJob skipped because another worker owns processing.', ['video_id' => $video->id]);

                return;
            }
            $video = $video->fresh();

            Log::info('ProcessVideoJob uploading video to Cloudinary.', [
                'video_id' => $video->id,
                'source_key' => $video->source_key,
                'upload_mode' => data_get($video->metadata, 'upload_mode'),
                'storage_disk' => data_get($video->metadata, 'storage_disk'),
                'original_name' => data_get($video->metadata, 'original_name'),
                'mime_type' => data_get($video->metadata, 'mime_type'),
                'size_bytes' => data_get($video->metadata, 'size'),
            ]);

            $result = $cloudinaryService->uploadVideoFromS3Key($video->source_key, $disk);
            $maxDuration = (int) config('video.max_duration_seconds', 120);
            $duration = (int) ($result['duration'] ?? 0);

            if ($duration > 0 && $duration > $maxDuration) {
                $video->update([
                    'status' => 'failed',
                    'processing_status' => 'processing_failed',
                    'processing_error' => 'Video duration exceeds the configured maximum.',
                    'failed_at' => now(),
                    'metadata' => array_merge($video->metadata ?? [], [
                        'error' => "Video duration must not exceed {$maxDuration} seconds.",
                    ]),
                ]);

                throw new RuntimeException("Video duration must not exceed {$maxDuration} seconds.");
            }

            $hlsUrl = $result['streaming_url'] ?? $result['stream_url'] ?? $result['cdn_url'];
            $resultMetadata = array_merge($video->metadata ?? [], $result['metadata'] ?? []);
            $video->update([
                'cdn_url' => $result['cdn_url'],
                'rendered_url' => $result['rendered_url'] ?? $video->rendered_url,
                'streaming_url' => $hlsUrl,
                'playback_type' => 'hls',
                'hls_url' => $hlsUrl,
                'fallback_mp4_url' => $result['rendered_url'] ?? null,
                'poster_url' => $result['poster_url'] ?? $result['thumbnail_url'] ?? null,
                'cloudinary_public_id' => $result['cloudinary_public_id'],
                'cloudinary_asset_id' => $result['cloudinary_asset_id'] ?? null,
                'thumbnail_url' => $video->thumbnail_url ?: ($result['poster_url'] ?? $result['thumbnail_url']),
                'duration' => $duration ?: null,
                'duration_ms' => $duration > 0 ? $duration * 1000 : null,
                'width' => $result['width'] ?? data_get($resultMetadata, 'width'),
                'height' => $result['height'] ?? data_get($resultMetadata, 'height'),
                'aspect_ratio' => $result['aspect_ratio'] ?? data_get($resultMetadata, 'aspect_ratio'),
                'fps' => $result['fps'] ?? data_get($resultMetadata, 'frame_rate'),
                'render_status' => 'ready',
                'processing_status' => 'ready',
                'processing_error' => null,
                'processed_at' => now(),
                'render_completed_at' => now(),
                'failed_at' => null,
                'metadata' => array_merge($resultMetadata, [
                    'stream_url' => $result['stream_url'] ?? $result['cdn_url'] ?? null,
                    'streaming_url' => $result['streaming_url'] ?? $result['stream_url'] ?? $result['cdn_url'] ?? null,
                    'poster_url' => $result['poster_url'] ?? $result['thumbnail_url'] ?? null,
                    'streaming_profile' => $result['streaming_profile'] ?? null,
                ]),
                'status' => 'ready',
            ]);

            event(new VideoUploaded($video->fresh()));

            Log::info('ProcessVideoJob completed successfully.', [
                'video_id' => $video->id,
                'cdn_url' => $video->cdn_url,
            ]);
        } catch (Throwable $throwable) {
            $video->update([
                'status' => 'failed',
                'processing_status' => 'processing_failed',
                'processing_error' => mb_substr($throwable->getMessage(), 0, 5000),
                'failed_at' => now(),
                'metadata' => array_merge($video->metadata ?? [], [
                    'error' => $throwable->getMessage(),
                ]),
            ]);

            Log::error('ProcessVideoJob failed.', [
                'video_id' => $video->id,
                'error' => $throwable->getMessage(),
                'exception' => get_class($throwable),
                'source_key' => $video->source_key,
                'upload_mode' => data_get($video->metadata, 'upload_mode'),
                'storage_disk' => data_get($video->metadata, 'storage_disk'),
                'original_name' => data_get($video->metadata, 'original_name'),
                'mime_type' => data_get($video->metadata, 'mime_type'),
                'size_bytes' => data_get($video->metadata, 'size'),
            ]);

            throw $throwable;
        }
    }
}
