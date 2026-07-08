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
use RuntimeException;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 60, 120];

    public function __construct(public Video $video)
    {
    }

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

        $video->update([
            'progress_percentage' => max((int) ($video->progress_percentage ?? 0), 50),
        ]);

        if ($video->status === 'ready' && $video->cdn_url && $video->cloudinary_public_id) {
            Log::info('ProcessVideoJob skipped because the video is already ready.', [
                'video_id' => $video->id,
            ]);

            return;
        }

        if (! $video->source_key) {
            $video->update([
                'status' => 'failed',
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
            Log::info('ProcessVideoJob uploading video to Cloudinary.', [
                'video_id' => $video->id,
                'source_key' => $video->source_key,
            ]);

            $video->update([
                'progress_percentage' => max((int) ($video->progress_percentage ?? 0), 75),
            ]);

            $result = $cloudinaryService->uploadVideoFromS3Key($video->source_key);

            $video->update([
                'cdn_url' => $result['cdn_url'],
                'cloudinary_public_id' => $result['cloudinary_public_id'],
                'thumbnail_url' => $result['thumbnail_url'],
                'duration' => $result['duration'],
                'metadata' => array_merge($video->metadata ?? [], $result['metadata'] ?? []),
                'status' => 'ready',
                'progress_percentage' => 100,
            ]);

            event(new VideoUploaded($video->fresh()));

            Log::info('ProcessVideoJob completed successfully.', [
                'video_id' => $video->id,
                'cdn_url' => $video->cdn_url,
            ]);
        } catch (Throwable $throwable) {
            $video->update([
                'status' => 'failed',
                'progress_percentage' => min((int) ($video->progress_percentage ?? 0), 99),
                'metadata' => array_merge($video->metadata ?? [], [
                    'error' => $throwable->getMessage(),
                ]),
            ]);

            Log::error('ProcessVideoJob failed.', [
                'video_id' => $video->id,
                'error' => $throwable->getMessage(),
                'exception' => get_class($throwable),
            ]);

            throw $throwable;
        }
    }
}
