<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\CloudinaryVideoRendererService;
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

    public int $timeout = 1200;

    public array $backoff = [30, 120];

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    public function __construct(
        public Video $video,
        public array $timeline,
    ) {}

    public function handle(
        CloudinaryVideoRendererService $cloudinaryRenderer,
        VideoEditRenderingService $ffmpegRenderer,
    ): void {
        $video = $this->video->fresh();

        if (! $video) {
            Log::warning('RenderVideoEditsJob skipped because the video record no longer exists.', [
                'video_id' => $this->video->id ?? null,
            ]);

            return;
        }

        try {
            $renderEngine = strtolower(trim((string) data_get($video->metadata, 'edit_renderer', 'auto')));
            $shouldUseFfmpeg = $renderEngine === 'ffmpeg'
                || ($renderEngine !== 'cloudinary' && $this->requiresFfmpegRenderer($this->timeline));

            $video->update([
                'status' => 'processing',
                'progress_percentage' => 25,
                'render_status' => 'processing',
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'rendering',
                    'processing_state' => 'rendering_edit',
                    'edit_started_at' => now()->toISOString(),
                    'render_timeline' => $this->timeline,
                    'render_engine' => $shouldUseFfmpeg ? 'ffmpeg' : 'cloudinary',
                ]),
            ]);

            $rendered = null;

            if ($shouldUseFfmpeg) {
                $rendered = $ffmpegRenderer->renderTimeline($video, $this->timeline);
            } else {
                try {
                    $rendered = $cloudinaryRenderer->startRender($video, $this->timeline);
                } catch (Throwable $cloudinaryFailure) {
                    if ($this->shouldFallbackToFfmpeg($cloudinaryFailure)) {
                        $shouldUseFfmpeg = true;
                        $rendered = $ffmpegRenderer->renderTimeline($video, $this->timeline);
                    } else {
                        throw $cloudinaryFailure;
                    }
                }
            }
            $video = $video->fresh() ?? $video;

            $renderStatus = (string) ($rendered['render_status'] ?? 'processing');
            $isReady = $renderStatus === 'ready';

            $video->update([
                'status' => $isReady ? 'ready' : 'processing',
                'processing_status' => $isReady ? 'ready' : 'processing',
                'processing_error' => null,
                'playback_type' => $isReady ? 'hls' : $video->playback_type,
                'hls_url' => $isReady ? ($rendered['streaming_url'] ?? $video->hls_url) : $video->hls_url,
                'fallback_mp4_url' => $isReady ? ($rendered['rendered_url'] ?? $video->fallback_mp4_url) : $video->fallback_mp4_url,
                'processing_started_at' => $video->processing_started_at ?: now(),
                'processed_at' => $isReady ? now() : null,
                'failed_at' => null,
                'progress_percentage' => $isReady ? 100 : 75,
                'render_status' => $renderStatus,
                'render_completed_at' => $isReady ? now() : $video->render_completed_at,
                'cloudinary_public_id' => $rendered['cloudinary_public_id'] ?? $video->cloudinary_public_id,
                'cloudinary_asset_id' => $rendered['cloudinary_asset_id'] ?? $video->cloudinary_asset_id,
                'cloudinary_render_id' => $rendered['cloudinary_render_id'] ?? $video->cloudinary_render_id,
                'cdn_url' => $rendered['cdn_url'] ?? $video->cdn_url,
                'rendered_url' => $rendered['rendered_url'] ?? $video->rendered_url,
                'streaming_url' => $rendered['streaming_url'] ?? $video->streaming_url,
                'poster_url' => $rendered['poster_url'] ?? $video->poster_url,
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => $isReady ? 'ready' : 'rendering',
                    'processing_state' => $isReady ? 'ready' : 'rendering_edit',
                    'render_requested_at' => now()->toISOString(),
                    'render_completed_at' => $isReady ? now()->toISOString() : null,
                    'render_plan' => $rendered['metadata'] ?? [],
                ]),
            ]);
            if ($isReady) {
                event(new VideoUploaded($video->fresh()));
            }
        } catch (Throwable $throwable) {
            $video->update([
                'status' => 'failed',
                'render_status' => 'failed',
                'processing_status' => 'processing_failed',
                'processing_error' => mb_substr($throwable->getMessage(), 0, 5000),
                'failed_at' => now(),
                'metadata' => array_merge($video->metadata ?? [], [
                    'edit_status' => 'failed',
                    'processing_state' => 'edit_failed',
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

    /**
     * @param  array<string, mixed>  $timeline
     */
    private function requiresFfmpegRenderer(array $timeline): bool
    {
        foreach ((array) ($timeline['layers'] ?? []) as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            if (in_array((string) ($layer['type'] ?? ''), ['captions', 'shape', 'transition'], true)) {
                return true;
            }

            if (! empty($layer['stroke'] ?? [])
                || ! empty($layer['shadow'] ?? [])
                || ! empty($layer['animation'] ?? [])
                || ! empty($layer['keyframes'] ?? [])
                || ! empty($layer['transition'] ?? [])
                || data_get($layer, 'metadata.transition') !== null
                || data_get($layer, 'preset') !== null
                || data_get($layer, 'content.preset') !== null
            ) {
                return true;
            }
        }

        return false;
    }

    private function shouldFallbackToFfmpeg(Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'unsupported timeline layer type')
            || str_contains($message, 'unsupported layer type')
            || str_contains($message, 'shape')
            || str_contains($message, 'transition')
            || str_contains($message, 'captions');
    }
}
