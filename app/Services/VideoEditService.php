<?php

namespace App\Services;

use App\Jobs\RenderVideoEditsJob;
use App\Models\Video;
use Illuminate\Validation\ValidationException;

class VideoEditService
{
    public function __construct(
        private readonly VideoProjectNormalizer $videoProjectNormalizer,
    ) {
    }

    /**
     * Queue a Cloudinary timeline render using the new frontend payload.
     *
     * @param  array<string, mixed>  $timeline
     */
    public function queueTimelineRender(Video $video, array $timeline, int $userId): Video
    {
        $video = $video->fresh();

        if (! $video) {
            throw ValidationException::withMessages([
                'video' => 'The selected video does not exist.',
            ]);
        }

        if ((int) $video->user_id !== $userId) {
            throw ValidationException::withMessages([
                'video' => 'You are not allowed to edit this video.',
            ]);
        }

        if (! $video->source_key) {
            throw ValidationException::withMessages([
                'video' => 'Upload a video before requesting edits.',
            ]);
        }

        $timeline = $this->applyVideoDurationFallback($video, $timeline);
        $normalizedProject = $this->videoProjectNormalizer->normalize($timeline, $userId);
        $normalizedTimeline = array_merge([
            'video_id' => (int) $video->id,
        ], $normalizedProject['timeline']);
        $renderEngine = $this->resolveRenderEngine($normalizedTimeline);

        $video->update([
            'status' => 'processing',
            'progress_percentage' => 0,
            'cdn_url' => null,
            'rendered_url' => null,
            'streaming_url' => null,
            'poster_url' => null,
            'cloudinary_public_id' => null,
            'cloudinary_asset_id' => null,
            'cloudinary_render_id' => null,
            'render_status' => 'queued',
            'render_completed_at' => null,
            'metadata' => array_merge($video->metadata ?? [], [
                'edit_status' => 'queued',
                'edit_requested_at' => now()->toISOString(),
                'schema_version' => $normalizedProject['schema_version'],
                'edit_project' => $normalizedProject['project'],
                'edit_overlays' => $normalizedTimeline['layers'],
                'render_timeline' => $normalizedTimeline,
                'edit_renderer' => $renderEngine,
                'previous_source_key' => $video->source_key,
                'previous_source_url' => $video->source_url,
                'previous_cloudinary_public_id' => $video->cloudinary_public_id,
                'previous_cdn_url' => $video->cdn_url,
                'previous_rendered_url' => $video->rendered_url,
                'previous_streaming_url' => $video->streaming_url,
            ]),
        ]);

        RenderVideoEditsJob::dispatch($video->fresh(), $normalizedTimeline)
            ->onQueue(config('video.processing_queue', 'videos'));

        return $video->fresh();
    }

    /**
     * If the client omits a track end time, treat it as running until the
     * source video ends. That keeps "no end" edits open-ended.
     *
     * @param  array<string, mixed>  $timeline
     * @return array<string, mixed>
     */
    private function applyVideoDurationFallback(Video $video, array $timeline): array
    {
        $duration = $video->duration;

        if (! is_numeric($duration) || (float) $duration <= 0) {
            $duration = data_get($video->metadata, 'duration_seconds');
        }

        if (! is_numeric($duration) || (float) $duration <= 0) {
            return $timeline;
        }

        $duration = (float) $duration;

        if (! array_key_exists('duration', $timeline) || $timeline['duration'] === null || $timeline['duration'] === '') {
            $timeline['duration'] = $duration;
        }

        return $timeline;
    }

    /**
     * @param  array<string, mixed>  $timeline
     */
    private function resolveRenderEngine(array $timeline): string
    {
        $forced = strtolower(trim((string) config('video.edit_renderer', 'auto')));

        if (in_array($forced, ['cloudinary', 'ffmpeg'], true)) {
            return $forced;
        }

        foreach ((array) ($timeline['layers'] ?? []) as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            $type = (string) ($layer['type'] ?? '');

            if (in_array($type, ['text', 'captions', 'shape', 'transition', 'image', 'sticker', 'drawing'], true)) {
                return 'ffmpeg';
            }

            if (in_array($type, ['audio'], true)) {
                $hasRichEffects = ! empty($layer['stroke'] ?? [])
                    || ! empty($layer['shadow'] ?? [])
                    || ! empty($layer['keyframes'] ?? [])
                    || ! empty($layer['animation'] ?? [])
                    || ! empty($layer['transition'] ?? [])
                    || ! empty(data_get($layer, 'metadata.transition', []))
                    || data_get($layer, 'preset') !== null
                    || data_get($layer, 'content.preset') !== null
                    || data_get($layer, 'font_weight') !== null
                    || data_get($layer, 'font_style') !== null
                    || data_get($layer, 'margin_x') !== null
                    || data_get($layer, 'margin_y') !== null
                    || data_get($layer, 'padding_x') !== null
                    || data_get($layer, 'padding_y') !== null;

                if ($hasRichEffects) {
                    return 'ffmpeg';
                }
            }
        }

        return 'cloudinary';
    }
}
