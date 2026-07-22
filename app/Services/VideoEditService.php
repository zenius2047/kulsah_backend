<?php

namespace App\Services;

use App\Jobs\RenderVideoEditsJob;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class VideoEditService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
    ) {
    }

    /**
     * Backward-compatible wrapper for the legacy overlay API.
     *
     * @param  array<int, array<string, mixed>>  $overlays
     * @param  array<int, UploadedFile>  $drawingFiles
     */
    public function queueRender(Video $video, array $overlays, array $drawingFiles, int $userId): Video
    {
        $timeline = $this->buildTimelineFromLegacyOverlays($overlays, $drawingFiles, $userId);

        return $this->queueTimelineRender($video, $timeline, $userId);
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

        $normalizedTimeline = $this->normalizeTimeline($timeline, $userId);

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
                'render_timeline' => $normalizedTimeline,
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
     * @param  array<int, array<string, mixed>>  $overlays
     * @param  array<int, UploadedFile>  $drawingFiles
     * @return array<string, mixed>
     */
    private function buildTimelineFromLegacyOverlays(array $overlays, array $drawingFiles, int $userId): array
    {
        $storedDrawings = [];

        foreach ($drawingFiles as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $storedDrawings[(int) $index] = $this->videoStorageService->uploadEditAsset($file, $userId);
        }

        $layers = [];

        foreach ($overlays as $index => $overlay) {
            $type = (string) ($overlay['type'] ?? '');

            if (! in_array($type, ['text', 'drawing'], true)) {
                throw ValidationException::withMessages([
                    "overlays.{$index}.type" => 'Overlay type must be text or drawing.',
                ]);
            }

            $start = max(0.0, (float) ($overlay['start'] ?? 0));
            $end = array_key_exists('end', $overlay) && $overlay['end'] !== null
                ? max(0.0, (float) $overlay['end'])
                : null;

            if ($end !== null && $end <= $start) {
                throw ValidationException::withMessages([
                    "overlays.{$index}.end" => 'Overlay end time must be greater than start time.',
                ]);
            }

            if ($type === 'text') {
                $text = trim((string) ($overlay['text'] ?? ''));

                if ($text === '') {
                    throw ValidationException::withMessages([
                        "overlays.{$index}.text" => 'Text overlays require text.',
                    ]);
                }

                $layers[] = array_filter([
                    'type' => 'text',
                    'text' => mb_substr($text, 0, 500),
                    'font' => (string) ($overlay['font'] ?? 'Arial'),
                    'size' => max(8, min(160, (int) ($overlay['font_size'] ?? 42))),
                    'color' => $this->normalizeColor($overlay['color'] ?? '#ffffff'),
                    'box_color' => $this->normalizeColor($overlay['box_color'] ?? 'black@0.35', true),
                    'box' => filter_var($overlay['box'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'x' => max(0, (int) round((float) ($overlay['x'] ?? 0))),
                    'y' => max(0, (int) round((float) ($overlay['y'] ?? 0))),
                    'start' => $start,
                    'end' => $end,
                ], static fn ($value) => $value !== null && $value !== '');

                continue;
            }

            $fileIndex = (int) ($overlay['file_index'] ?? $overlay['drawing_file_index'] ?? -1);

            if (! isset($storedDrawings[$fileIndex])) {
                throw ValidationException::withMessages([
                    "overlays.{$index}.file_index" => 'Drawing overlays require a matching drawing_files index.',
                ]);
            }

            $drawing = $storedDrawings[$fileIndex];

            $layers[] = array_filter([
                'type' => 'drawing',
                'asset_key' => $drawing['source_key'],
                'asset_disk' => $drawing['disk'],
                'asset_url' => $this->videoStorageService->resolveAccessibleUrl($drawing['disk'], $drawing['source_key']),
                'x' => max(0, (int) round((float) ($overlay['x'] ?? 0))),
                'y' => max(0, (int) round((float) ($overlay['y'] ?? 0))),
                'start' => $start,
                'end' => $end,
                'width' => isset($overlay['width']) ? max(1, (int) $overlay['width']) : null,
                'height' => isset($overlay['height']) ? max(1, (int) $overlay['height']) : null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return [
            'video_id' => null,
            'layers' => $layers,
            'filters' => [],
            'audio' => [],
            'trim' => [],
            'output' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @return array<string, mixed>
     */
    private function normalizeTimeline(array $timeline, int $userId): array
    {
        $layersInput = $timeline['layers'] ?? $timeline['overlays'] ?? [];

        if (! is_array($layersInput) || $layersInput === []) {
            throw ValidationException::withMessages([
                'layers' => 'At least one render layer is required.',
            ]);
        }

        $layers = [];

        foreach (array_values($layersInput) as $index => $layer) {
            if (! is_array($layer)) {
                throw ValidationException::withMessages([
                    "layers.{$index}" => 'Each layer must be an array.',
                ]);
            }

            $layers[] = $this->normalizeLayer($layer, $index, $userId);
        }

        return [
            'video_id' => isset($timeline['video_id']) ? (int) $timeline['video_id'] : null,
            'layers' => $layers,
            'filters' => $this->normalizeFilters($timeline['filters'] ?? []),
            'audio' => is_array($timeline['audio'] ?? null) ? array_filter($timeline['audio']) : [],
            'trim' => $this->normalizeTrim($timeline['trim'] ?? []),
            'output' => $this->normalizeOutput($timeline['output'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private function normalizeLayer(array $layer, int $index, int $userId): array
    {
        $type = (string) ($layer['type'] ?? '');

        if (! in_array($type, ['text', 'drawing', 'image', 'sticker', 'watermark', 'audio'], true)) {
            throw ValidationException::withMessages([
                "layers.{$index}.type" => 'Unsupported layer type.',
            ]);
        }

        $normalized = [
            'type' => $type,
            'x' => max(0, (int) round((float) ($layer['x'] ?? 0))),
            'y' => max(0, (int) round((float) ($layer['y'] ?? 0))),
            'start' => max(0, (float) ($layer['start'] ?? 0)),
            'end' => array_key_exists('end', $layer) && $layer['end'] !== null
                ? max(0, (float) $layer['end'])
                : null,
            'gravity' => (string) ($layer['gravity'] ?? 'north_west'),
            'public_id' => isset($layer['public_id']) ? trim((string) $layer['public_id'], '/') : null,
            'asset_public_id' => isset($layer['asset_public_id']) ? trim((string) $layer['asset_public_id'], '/') : null,
            'asset_url' => isset($layer['asset_url']) ? (string) $layer['asset_url'] : null,
            'asset_disk' => isset($layer['asset_disk']) ? (string) $layer['asset_disk'] : null,
            'asset_key' => isset($layer['asset_key']) ? (string) $layer['asset_key'] : null,
            'width' => isset($layer['width']) ? max(1, (int) $layer['width']) : null,
            'height' => isset($layer['height']) ? max(1, (int) $layer['height']) : null,
        ];

        if (($normalized['asset_url'] === null || $normalized['asset_url'] === '') && $normalized['asset_disk'] && $normalized['asset_key']) {
            $normalized['asset_url'] = $this->videoStorageService->resolveAccessibleUrl($normalized['asset_disk'], $normalized['asset_key']);
        }

        if ($type === 'text') {
            $text = trim((string) ($layer['text'] ?? ''));

            if ($text === '') {
                throw ValidationException::withMessages([
                    "layers.{$index}.text" => 'Text layers require text.',
                ]);
            }

            $normalized['font'] = (string) ($layer['font'] ?? 'Arial');
            $normalized['size'] = max(8, (int) ($layer['size'] ?? $layer['font_size'] ?? 42));
            $normalized['color'] = $this->normalizeColor($layer['color'] ?? '#ffffff');
            $normalized['box'] = filter_var($layer['box'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $normalized['box_color'] = $this->normalizeColor($layer['box_color'] ?? 'black@0.35', true);
            $normalized['text'] = mb_substr($text, 0, 500);
        } elseif ($normalized['public_id'] === null && $normalized['asset_public_id'] === null && ($normalized['asset_url'] === null || $normalized['asset_url'] === '')) {
            throw ValidationException::withMessages([
                "layers.{$index}.public_id" => 'Non-text layers require a public_id, asset_url, or renderable asset reference.',
            ]);
        }

        return array_filter($normalized, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  mixed  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $normalized = [];

        foreach (['brightness', 'contrast', 'saturation', 'hue', 'gamma'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $normalized[$key] = (int) $filters[$key];
            }
        }

        foreach (['grayscale', 'sepia'] as $key) {
            if (array_key_exists($key, $filters)) {
                $normalized[$key] = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $normalized;
    }

    /**
     * @param  mixed  $trim
     * @return array<string, mixed>
     */
    private function normalizeTrim(mixed $trim): array
    {
        if (! is_array($trim)) {
            return [];
        }

        $normalized = [];

        if (array_key_exists('start', $trim) && $trim['start'] !== null && $trim['start'] !== '') {
            $normalized['start'] = max(0, (float) $trim['start']);
        }

        if (array_key_exists('end', $trim) && $trim['end'] !== null && $trim['end'] !== '') {
            $normalized['end'] = max(0, (float) $trim['end']);
        }

        return $normalized;
    }

    /**
     * @param  mixed  $output
     * @return array<string, mixed>
     */
    private function normalizeOutput(mixed $output): array
    {
        if (! is_array($output)) {
            return [];
        }

        $normalized = [];

        foreach (['format', 'quality', 'crop', 'audio'] as $key) {
            if (array_key_exists($key, $output) && $output[$key] !== null && $output[$key] !== '') {
                $normalized[$key] = (string) $output[$key];
            }
        }

        foreach (['width', 'height', 'fps'] as $key) {
            if (array_key_exists($key, $output) && $output[$key] !== null && $output[$key] !== '') {
                $normalized[$key] = (int) $output[$key];
            }
        }

        if (array_key_exists('bit_rate', $output) && $output['bit_rate'] !== null && $output['bit_rate'] !== '') {
            $normalized['bit_rate'] = (string) $output['bit_rate'];
        }

        if (array_key_exists('poster', $output) && is_array($output['poster'])) {
            $normalized['poster'] = array_filter([
                'width' => isset($output['poster']['width']) ? (int) $output['poster']['width'] : null,
                'height' => isset($output['poster']['height']) ? (int) $output['poster']['height'] : null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return $normalized;
    }

    private function normalizeColor(mixed $color, bool $allowAlpha = false): string
    {
        $color = trim((string) $color);
        $pattern = $allowAlpha
            ? '/^(#[0-9A-Fa-f]{6}|[A-Za-z]+)(@[0-9.]+)?$/'
            : '/^(#[0-9A-Fa-f]{6}|[A-Za-z]+)$/';

        return preg_match($pattern, $color) === 1 ? $color : '#ffffff';
    }
}
