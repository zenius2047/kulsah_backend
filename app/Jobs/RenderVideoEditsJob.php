<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoEditRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderVideoEditsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 360;

    public array $backoff = [30, 120];

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    public function __construct(
        public Video $video,
        public array $overlays,
    ) {
    }

    public function handle(VideoEditRenderingService $renderingService): void
    {
        $video = $this->video->fresh();

        if (! $video) {
            Log::warning('RenderVideoEditsJob skipped because the video record no longer exists.', [
                'video_id' => $this->video->id ?? null,
            ]);

            return;
        }

        try {
            $video->update([
                'status' => 'processing',
                'progress_percentage' => 25,
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'rendering',
                    'edit_started_at' => now()->toISOString(),
                ]),
            ]);

            $rendered = $renderingService->render($video, $this->overlays);

            $video->update([
                'source_url' => $rendered['source_url'],
                'source_key' => $rendered['source_key'],
                'progress_percentage' => 75,
                'metadata' => array_merge($video->metadata ?? [], [
                    'storage_disk' => $rendered['disk'],
                    'edit_status' => 'rendered',
                    'edit_rendered_at' => now()->toISOString(),
                    'edited_source_key' => $rendered['source_key'],
                    'edited_source_url' => $rendered['source_url'],
                ]),
            ]);

            ProcessVideoJob::dispatch($video->fresh())->onQueue(config('video.processing_queue', 'videos'));
        } catch (Throwable $throwable) {
            $video->update([
                'status' => 'failed',
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'failed',
                    'edit_error' => $throwable->getMessage(),
                ]),
            ]);

            Log::error('RenderVideoEditsJob failed.', [
                'video_id' => $video->id,
                'error' => $throwable->getMessage(),
                'exception' => get_class($throwable),
            ]);

            throw $throwable;
        }
    }
}
