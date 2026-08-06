<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class VideoEditRenderingService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly CloudinaryService $cloudinaryService,
    ) {
    }

    /**
     * Render a normalized rich timeline. This is the path used for TikTok-style
     * edits that need more than the Cloudinary renderer can express cleanly.
     *
     * @param  array<string, mixed>  $timeline
     */
    public function renderTimeline(Video $video, array $timeline): array
    {
        return $this->render($video, (array) ($timeline['layers'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    public function render(Video $video, array $overlays): array
    {
        $sourceDisk = data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));
        $sourcePath = $this->copyStorageFileToTemp($sourceDisk, (string) $video->source_key, 'kulsah-edit-source-', '.mp4');
        $outputPath = $this->makeTempPath('kulsah-edit-render-', '.mp4');
        $overlayInputs = [];
        $preparedOverlays = [];
        $overlayTempFiles = [];

        try {
            foreach ($overlays as $overlay) {
                if (! is_array($overlay)) {
                    continue;
                }

                if (! $this->requiresOverlayInput($overlay)) {
                    $preparedOverlays[] = $overlay;
                    continue;
                }

                $materializedOverlay = $this->materializeOverlayInput($overlay);
                $overlayTempFiles[] = $materializedOverlay['cleanup'];
                $overlayInputs[] = [
                    'source' => $materializedOverlay['source'],
                    'loop' => $this->shouldLoopOverlayInput($overlay),
                ];
                $overlay['input_index'] = count($overlayInputs);
                $preparedOverlays[] = $overlay;
            }

            $command = $this->buildCommand($sourcePath, $overlayInputs, $preparedOverlays, $outputPath);
            $process = new Process($command);
            $process->setTimeout($this->resolveProcessTimeoutSeconds($video, $preparedOverlays));
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('FFmpeg edit render failed.', [
                    'video_id' => $video->id,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                throw new RuntimeException('Video edit render failed: '.trim($process->getErrorOutput()));
            }

            return $this->cloudinaryService->uploadVideoFromLocalPath(
                localPath: $outputPath,
                originalName: pathinfo((string) $video->source_key, PATHINFO_FILENAME).'-edited.mp4'
            );
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
            foreach ($overlayTempFiles as $overlayTempFile) {
                if (is_string($overlayTempFile) && $overlayTempFile !== '') {
                    @unlink($overlayTempFile);
                }
            }
        }
    }

    /**
     * @param  array<int, array{source:string,loop:bool}>  $overlayInputs
     * @param  array<int, array<string, mixed>>  $overlays
     * @return array<int, string>
     */
    private function buildCommand(string $sourcePath, array $overlayInputs, array $overlays, string $outputPath): array
    {
        $command = ['ffmpeg', '-y', '-i', $sourcePath];

        foreach ($overlayInputs as $overlayInput) {
            if (($overlayInput['loop'] ?? false) === true) {
                array_push($command, '-loop', '1');
            }

            array_push($command, '-i', (string) $overlayInput['source']);
        }

        $filter = $this->buildFilterGraph($overlays);

        array_push(
            $command,
            '-filter_complex',
            $filter,
            '-map',
            '[vout]',
            '-map',
            '0:a?',
            '-c:v',
            'libx264',
            '-pix_fmt',
            'yuv420p',
            '-preset',
            (string) config('video.transcode_preset', 'veryfast'),
            '-crf',
            (string) config('video.transcode_crf', 28),
            '-c:a',
            'aac',
            '-b:a',
            (string) config('video.transcode_audio_bitrate', '128k'),
            '-movflags',
            '+faststart',
            $outputPath
        );

        return $command;
    }

    /**
     * @param  array<string, mixed>  $overlay
     */
    private function requiresOverlayInput(array $overlay): bool
    {
        return ! in_array((string) ($overlay['type'] ?? ''), ['text', 'captions', 'shape'], true);
    }

    /**
     * Still-image overlays need to be looped so they are present for the
     * entire output video duration instead of acting like one-frame inputs.
     *
     * @param  array<string, mixed>  $overlay
     */
    private function shouldLoopOverlayInput(array $overlay): bool
    {
        return in_array((string) ($overlay['type'] ?? ''), ['image', 'sticker', 'drawing'], true);
    }

    /**
     * @param  array<string, mixed>  $overlay
     */
    private function materializeOverlayInput(array $overlay): array
    {
        $sourcePath = $this->resolveOverlaySourcePath($overlay);

        if ($this->looksLikeLocalFilePath($sourcePath) && is_file($sourcePath)) {
            return [
                'source' => $sourcePath,
                'cleanup' => $sourcePath,
            ];
        }

        $tempPath = $this->copySourceToTemp($sourcePath, (string) ($overlay['type'] ?? 'overlay'));

        return [
            'source' => $tempPath,
            'cleanup' => $tempPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $overlay
     */
    private function resolveOverlaySourcePath(array $overlay): string
    {
        $assetUrl = (string) data_get($overlay, 'asset_url', '');

        if ($assetUrl !== '') {
            return $assetUrl;
        }

        $assetDisk = (string) data_get($overlay, 'asset_disk', '');
        $assetKey = (string) data_get($overlay, 'asset_key', '');

        if ($assetDisk !== '' && $assetKey !== '') {
            $stream = Storage::disk($assetDisk)->readStream($assetKey);

            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to read the overlay asset from storage.');
            }

            $tempPath = $this->makeTempPath('kulsah-edit-overlay-', '.bin');
            $target = fopen($tempPath, 'w+b');

            if ($target === false) {
                fclose($stream);
                throw new RuntimeException('Unable to open a temporary file for the overlay asset.');
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($stream);
                fclose($target);
            }

            return $tempPath;
        }

        $publicId = (string) (data_get($overlay, 'public_id') ?? data_get($overlay, 'asset_public_id') ?? '');

        if ($publicId !== '') {
            $type = (string) ($overlay['type'] ?? '');

            if ($type === 'video') {
                return $this->cloudinaryService->generateDerivedVideoUrl($publicId);
            }

            return $this->cloudinaryService->generateImageUrlFromPublicId($publicId);
        }

        throw new RuntimeException('Timeline overlay is missing a renderable source.');
    }

    private function copySourceToTemp(string $source, string $overlayType): string
    {
        $tempPath = $this->makeTempPath('kulsah-edit-overlay-', $this->guessOverlayExtension($source, $overlayType));

        if ($this->looksLikeLocalFilePath($source) && is_file($source)) {
            if (! copy($source, $tempPath)) {
                @unlink($tempPath);
                throw new RuntimeException('Unable to copy the overlay asset to a temporary file.');
            }

            return $tempPath;
        }

        $response = Http::timeout((int) config('video.edit_overlay_fetch_timeout_seconds', 45))
            ->retry(2, 250)
            ->sink($tempPath)
            ->get($source);

        if (! $response->successful()) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to download the overlay asset for rendering.');
        }

        return $tempPath;
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    private function buildFilterGraph(array $overlays): string
    {
        $filters = [];
        $current = '[0:v]';
        $step = 0;

        foreach ($overlays as $overlay) {
            $next = '[v'.$step.']';
            $enable = $this->enableExpression((float) ($overlay['start'] ?? 0), $overlay['end'] ?? null);

            if (in_array((string) ($overlay['type'] ?? ''), ['text', 'captions'], true)) {
                $stroke = is_array($overlay['stroke'] ?? null) ? $overlay['stroke'] : [];
                $shadow = is_array($overlay['shadow'] ?? null) ? $overlay['shadow'] : [];
                $animation = is_array($overlay['animation'] ?? null) ? $overlay['animation'] : [];
                $transition = is_array($overlay['transition'] ?? null) ? $overlay['transition'] : [];
                $preset = (string) ($overlay['preset'] ?? 'caption');
                $xExpression = $this->buildHorizontalPositionExpression($overlay, $preset);
                $yExpression = $this->buildVerticalPositionExpression($overlay, $preset);
                $alphaExpression = $this->buildAlphaExpression($overlay, (float) ($overlay['opacity'] ?? 1.0));
                $drawText = [
                    'text='.$this->escapeFilterValue((string) $overlay['text']),
                    'x='.$xExpression,
                    'y='.$yExpression,
                    'fontsize='.(int) ($overlay['font_size'] ?? 42),
                    'fontcolor='.$this->escapeFilterValue((string) ($overlay['color'] ?? '#ffffff')),
                    'alpha='.$alphaExpression,
                    'fix_bounds=1',
                    "enable='{$enable}'",
                ];

                $fontFile = (string) config('video.ffmpeg_font_file', '');
                if ($fontFile !== '' && is_file($fontFile)) {
                    $drawText[] = 'fontfile='.$this->escapeFilterValue($fontFile);
                }

                if ((bool) ($overlay['box'] ?? true)) {
                    $drawText[] = 'box=1';
                    $drawText[] = 'boxcolor='.$this->escapeFilterValue((string) ($overlay['box_color'] ?? 'black@0.35'));
                    $drawText[] = 'boxborderw='.(int) max(1, (int) ($overlay['box_border_width'] ?? 12));
                }

                if ($stroke !== []) {
                    $drawText[] = 'borderw='.(int) max(1, (int) ($stroke['width'] ?? $stroke['size'] ?? 2));
                    $drawText[] = 'bordercolor='.$this->escapeFilterValue((string) ($stroke['color'] ?? '#000000'));
                }

                if ($shadow !== []) {
                    $drawText[] = 'shadowx='.(int) ($shadow['x'] ?? 2);
                    $drawText[] = 'shadowy='.(int) ($shadow['y'] ?? 2);
                    $drawText[] = 'shadowcolor='.$this->escapeFilterValue((string) ($shadow['color'] ?? 'black@0.5'));
                }

                $filters[] = $current.'drawtext='.implode(':', $drawText).$next;
                $current = $next;
                $step++;
                continue;
            }

            if (($overlay['type'] ?? null) === 'shape') {
                $shape = is_array($overlay['shape'] ?? null) ? $overlay['shape'] : [];
                $shapeColor = (string) ($shape['color'] ?? $overlay['color'] ?? '#ffffff');
                $shapeOpacity = $this->normalizeOpacity($overlay['opacity'] ?? $shape['opacity'] ?? 1.0);
                $shapeWidth = (int) max(1, (int) ($overlay['width'] ?? 200));
                $shapeHeight = (int) max(1, (int) ($overlay['height'] ?? 100));
                $shapeX = $this->buildNumericPositionExpression($overlay, 'x', (string) ($overlay['x'] ?? 0));
                $shapeY = $this->buildNumericPositionExpression($overlay, 'y', (string) ($overlay['y'] ?? 0));
                $shapeColor = str_starts_with($shapeColor, '#')
                    ? '0x'.ltrim($shapeColor, '#')
                    : $shapeColor;

                $filters[] = $current.'drawbox='
                    .'x='.$shapeX
                    .':y='.$shapeY
                    .':w='.$shapeWidth
                    .':h='.$shapeHeight
                    .':color='.$this->escapeFilterValue((string) $shapeColor.'@'.($shapeOpacity / 100))
                    .':t=fill'
                    .$next;
                $current = $next;
                $step++;
                continue;
            }

            $inputIndex = (int) ($overlay['input_index'] ?? ($step + 1));
            $overlayInput = '['.$inputIndex.':v]';
            if (($overlay['width'] ?? null) || ($overlay['height'] ?? null)) {
                $scaled = '[layer'.$step.']';
                $width = $overlay['width'] ?? -1;
                $height = $overlay['height'] ?? -1;
                $filters[] = $overlayInput.'scale='.(int) $width.':'.(int) $height.$scaled;
                $overlayInput = $scaled;
            }

            $overlayX = $this->buildNumericPositionExpression($overlay, 'x', (string) ($overlay['x'] ?? 0));
            $overlayY = $this->buildNumericPositionExpression($overlay, 'y', (string) ($overlay['y'] ?? 0));

            $filters[] = $current.$overlayInput.'overlay='.$overlayX.':'.$overlayY.":enable='{$enable}'".$next;
            $current = $next;
            $step++;
        }

        $filters[] = $current.'format=yuv420p[vout]';

        return implode(';', $filters);
    }

    private function enableExpression(float $start, mixed $end): string
    {
        if ($end === null || $end === '') {
            return 'gte(t,'.max(0, $start).')';
        }

        return 'between(t,'.max(0, $start).','.max(0, (float) $end).')';
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function buildHorizontalPositionExpression(array $layer, string $preset = 'caption'): string
    {
        $keyframeExpression = $this->buildKeyframeExpression($layer, 'x');

        if ($keyframeExpression !== null) {
            return $keyframeExpression;
        }

        $base = (string) ($layer['x'] ?? '0');
        $animation = is_array($layer['animation'] ?? null) ? $layer['animation'] : [];
        $transition = is_array($layer['transition'] ?? null) ? $layer['transition'] : [];
        $offset = 0;

        $motionPreset = strtolower((string) ($animation['preset'] ?? $transition['enter'] ?? ''));
        if (in_array($motionPreset, ['slide_left', 'slide_right'], true)) {
            $offset = 60;
        }

        if ($offset > 0 && $motionPreset === 'slide_left') {
            return sprintf('(%s)+(%d*(1-min(1,(t-%s)/0.35)))', $base, $offset, (string) ($layer['start'] ?? 0));
        }

        if ($offset > 0 && $motionPreset === 'slide_right') {
            return sprintf('(%s)-(%d*(1-min(1,(t-%s)/0.35)))', $base, $offset, (string) ($layer['start'] ?? 0));
        }

        if (in_array(strtolower($preset), ['caption', 'subtitle', 'tiktok_caption'], true) && trim($base) === '0') {
            return '(w-text_w)/2';
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function buildVerticalPositionExpression(array $layer, string $preset = 'caption'): string
    {
        $keyframeExpression = $this->buildKeyframeExpression($layer, 'y');

        if ($keyframeExpression !== null) {
            return $keyframeExpression;
        }

        $base = (string) ($layer['y'] ?? '0');
        $animation = is_array($layer['animation'] ?? null) ? $layer['animation'] : [];
        $transition = is_array($layer['transition'] ?? null) ? $layer['transition'] : [];
        $motionPreset = strtolower((string) ($animation['preset'] ?? $transition['enter'] ?? ''));

        if (in_array(strtolower($preset), ['caption', 'subtitle', 'tiktok_caption'], true) && trim($base) === '0') {
            return 'h*0.74';
        }

        if ($motionPreset === 'slide_up') {
            return sprintf('(%s)+(40*(1-min(1,(t-%s)/0.35)))', $base, (string) ($layer['start'] ?? 0));
        }

        if ($motionPreset === 'slide_down') {
            return sprintf('(%s)-(40*(1-min(1,(t-%s)/0.35)))', $base, (string) ($layer['start'] ?? 0));
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function buildAlphaExpression(array $layer, float $fallbackOpacity): string
    {
        $keyframeExpression = $this->buildKeyframeExpression($layer, 'opacity');
        if ($keyframeExpression !== null) {
            return $keyframeExpression;
        }

        $animation = is_array($layer['animation'] ?? null) ? $layer['animation'] : [];
        $transition = is_array($layer['transition'] ?? null) ? $layer['transition'] : [];
        $preset = strtolower((string) ($animation['preset'] ?? $transition['enter'] ?? ''));
        $start = (string) ($layer['start'] ?? '0');
        $duration = (float) ($animation['duration'] ?? $transition['duration'] ?? 0.35);

        if ($preset === 'fade_in') {
            return sprintf('if(lt(t,%s),0,if(lt(t,%s),(t-%s)/%s,1))', $start, ((float) $start + $duration), $start, max(0.001, $duration));
        }

        if ($preset === 'fade_out' && array_key_exists('end', $layer) && $layer['end'] !== null && $layer['end'] !== '') {
            $end = (string) $layer['end'];
            $fadeStart = max(0.0, (float) $layer['end'] - $duration);

            return sprintf('if(lt(t,%s),1,if(lt(t,%s),(%s-t)/%s,0))', $fadeStart, $end, $end, max(0.001, $duration));
        }

        return (string) max(0.0, min(1.0, $fallbackOpacity));
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function buildNumericPositionExpression(array $layer, string $axis, string $fallback): string
    {
        $keyframeExpression = $this->buildKeyframeExpression($layer, $axis);

        if ($keyframeExpression !== null) {
            return $keyframeExpression;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function buildKeyframeExpression(array $layer, string $property): ?string
    {
        $keyframes = array_values(array_filter(
            is_array($layer['keyframes'] ?? null) ? $layer['keyframes'] : [],
            static fn ($keyframe) => is_array($keyframe) && (array_key_exists($property, $keyframe) || array_key_exists('x', $keyframe) || array_key_exists('y', $keyframe) || array_key_exists('opacity', $keyframe))
        ));

        if ($keyframes === []) {
            return null;
        }

        usort($keyframes, static function (array $left, array $right): int {
            $leftTime = (float) ($left['time'] ?? $left['at'] ?? 0);
            $rightTime = (float) ($right['time'] ?? $right['at'] ?? 0);

            return $leftTime <=> $rightTime;
        });

        $fallback = (float) ($layer[$property] ?? ($property === 'opacity' ? 1.0 : 0.0));
        $segments = [];
        $previousTime = null;
        $previousValue = $fallback;

        foreach ($keyframes as $index => $keyframe) {
            $time = (float) ($keyframe['time'] ?? $keyframe['at'] ?? 0);
            $value = (float) ($keyframe[$property] ?? ($property === 'opacity' ? ($keyframe['value'] ?? $fallback) : $fallback));

            if ($index === 0) {
                $segments[] = sprintf('if(lt(t,%s),%s', $time, $previousValue);
                $previousTime = $time;
                $previousValue = $value;
                continue;
            }

            $duration = max(0.001, $time - (float) $previousTime);
            $segments[] = sprintf(
                ',if(lt(t,%s),(%s)+((t-%s)*((%s)-(%s))/%s)',
                $time,
                $previousValue,
                $previousTime,
                $value,
                $previousValue,
                $duration
            );
            $previousTime = $time;
            $previousValue = $value;
        }

        $segments[] = sprintf(',%s', $previousValue);

        return implode('', $segments).str_repeat(')', max(0, count($segments) - 1));
    }

    private function normalizeOpacity(mixed $opacity): int
    {
        if ($opacity === null || $opacity === '') {
            return 100;
        }

        return (int) round(max(0.0, min(1.0, (float) $opacity)) * 100);
    }

    private function escapeFilterValue(string $value): string
    {
        return str_replace(
            ['\\', ':', "'", ',', '[', ']'],
            ['\\\\', '\\:', "\\'", '\\,', '\\[', '\\]'],
            $value
        );
    }

    private function copyStorageFileToTemp(string $disk, string $sourceKey, string $prefix, string $extension): string
    {
        $stream = Storage::disk($disk)->readStream($sourceKey);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read {$sourceKey} from storage.");
        }

        $path = $this->makeTempPath($prefix, $extension);
        $target = fopen($path, 'w+b');

        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Unable to open a temporary render file.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        return $path;
    }

    private function looksLikeLocalFilePath(string $source): bool
    {
        return str_starts_with($source, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $source) === 1;
    }

    private function guessOverlayExtension(string $source, string $overlayType): string
    {
        $path = parse_url($source, PHP_URL_PATH);
        $extension = is_string($path) ? pathinfo($path, PATHINFO_EXTENSION) : '';

        if ($extension !== '') {
            return '.'.strtolower($extension);
        }

        return match ($overlayType) {
            'video' => '.mp4',
            'image', 'sticker', 'drawing' => '.png',
            default => '.bin',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    private function resolveProcessTimeoutSeconds(Video $video, array $overlays): int
    {
        $configuredTimeout = max(1, (int) config('video.edit_render_timeout_seconds', 300));
        $durationSeconds = $this->resolveVideoDurationSeconds($video);
        $overlayCount = count($overlays);
        $estimatedTimeout = 300;

        if ($durationSeconds > 0) {
            $estimatedTimeout = (int) ceil($durationSeconds * 15) + ($overlayCount * 5);
        }

        return max($configuredTimeout, min(1800, max(300, $estimatedTimeout)));
    }

    private function resolveVideoDurationSeconds(Video $video): float
    {
        $duration = $video->duration;

        if (! is_numeric($duration) || (float) $duration <= 0) {
            $duration = data_get($video->metadata, 'duration_seconds');
        }

        if (! is_numeric($duration) || (float) $duration <= 0) {
            $duration = data_get($video->metadata, 'duration');
        }

        return is_numeric($duration) ? max(0.0, (float) $duration) : 0.0;
    }

    private function makeTempPath(string $prefix, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary render file.');
        }

        $target = $path.$extension;
        @unlink($path);

        return $target;
    }
}
