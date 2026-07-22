<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\CloudinaryVideoRendererService;
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
        public array $timeline,
    ) {
    }

    public function handle(CloudinaryVideoRendererService $renderingService): void
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
                'render_status' => 'processing',
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'rendering',
                    'edit_started_at' => now()->toISOString(),
                    'render_timeline' => $this->timeline,
                ]),
            ]);

            $rendered = $renderingService->startRender($video, $this->timeline);

            $video->update([
                'progress_percentage' => 75,
                'render_status' => $rendered['render_status'] ?? 'processing',
                'cloudinary_asset_id' => $rendered['cloudinary_asset_id'] ?? $video->cloudinary_asset_id,
                'cloudinary_render_id' => $rendered['cloudinary_render_id'] ?? $video->cloudinary_render_id,
                'rendered_url' => $rendered['rendered_url'] ?? $video->rendered_url,
                'streaming_url' => $rendered['streaming_url'] ?? $video->streaming_url,
                'poster_url' => $rendered['poster_url'] ?? $video->poster_url,
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'rendering',
                    'render_requested_at' => now()->toISOString(),
                    'render_plan' => $rendered['metadata'] ?? [],
                ]),
            ]);
        } catch (Throwable $throwable) {
            $video->update([
                'status' => 'failed',
                'render_status' => 'failed',
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
