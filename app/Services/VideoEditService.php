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
     * @param  array<int, array<string, mixed>>  $overlays
     * @param  array<int, UploadedFile>  $drawingFiles
     */
    public function queueRender(Video $video, array $overlays, array $drawingFiles, int $userId): Video
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

        $storedDrawings = [];
        foreach ($drawingFiles as $index => $file) {
            if ($file instanceof UploadedFile) {
                $storedDrawings[(int) $index] = $this->videoStorageService->uploadEditAsset($file, $userId);
            }
        }

        $normalizedOverlays = $this->normalizeOverlays($overlays, $storedDrawings);

        $video->update([
            'status' => 'processing',
            'progress_percentage' => 0,
            'cdn_url' => null,
            'cloudinary_public_id' => null,
            'metadata' => array_merge($video->metadata ?? [], [
                'edit_status' => 'queued',
                'edit_requested_at' => now()->toISOString(),
                'edit_overlays' => $normalizedOverlays,
                'edit_assets' => array_values($storedDrawings),
                'previous_source_key' => $video->source_key,
                'previous_source_url' => $video->source_url,
                'previous_cloudinary_public_id' => $video->cloudinary_public_id,
                'previous_cdn_url' => $video->cdn_url,
            ]),
        ]);

        RenderVideoEditsJob::dispatch($video->fresh(), $normalizedOverlays)
            ->onQueue(config('video.processing_queue', 'videos'));

        return $video->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     * @param  array<int, array<string, string>>  $storedDrawings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOverlays(array $overlays, array $storedDrawings): array
    {
        $normalized = [];

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

            $base = [
                'type' => $type,
                'x' => max(0, (int) round((float) ($overlay['x'] ?? 0))),
                'y' => max(0, (int) round((float) ($overlay['y'] ?? 0))),
                'start' => $start,
                'end' => $end,
            ];

            if ($type === 'text') {
                $text = trim((string) ($overlay['text'] ?? ''));

                if ($text === '') {
                    throw ValidationException::withMessages([
                        "overlays.{$index}.text" => 'Text overlays require text.',
                    ]);
                }

                $normalized[] = array_merge($base, [
                    'text' => mb_substr($text, 0, 500),
                    'font_size' => max(8, min(160, (int) ($overlay['font_size'] ?? 42))),
                    'color' => $this->normalizeColor($overlay['color'] ?? '#ffffff'),
                    'box_color' => $this->normalizeColor($overlay['box_color'] ?? 'black@0.35', true),
                    'box' => filter_var($overlay['box'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ]);

                continue;
            }

            $fileIndex = (int) ($overlay['file_index'] ?? $overlay['drawing_file_index'] ?? -1);

            if (! isset($storedDrawings[$fileIndex])) {
                throw ValidationException::withMessages([
                    "overlays.{$index}.file_index" => 'Drawing overlays require a matching drawing_files index.',
                ]);
            }

            $normalized[] = array_merge($base, [
                'asset_key' => $storedDrawings[$fileIndex]['source_key'],
                'asset_disk' => $storedDrawings[$fileIndex]['disk'],
                'width' => isset($overlay['width']) ? max(1, (int) $overlay['width']) : null,
                'height' => isset($overlay['height']) ? max(1, (int) $overlay['height']) : null,
            ]);
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
