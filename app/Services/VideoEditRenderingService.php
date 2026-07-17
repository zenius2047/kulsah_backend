<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class VideoEditRenderingService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $overlays
     */
    public function render(Video $video, array $overlays): array
    {
        $sourceDisk = data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));
        $sourcePath = $this->copyStorageFileToTemp($sourceDisk, (string) $video->source_key, 'kulsah-edit-source-', '.mp4');
        $outputPath = $this->makeTempPath('kulsah-edit-render-', '.mp4');
        $drawingPaths = [];

        try {
            foreach ($overlays as $overlay) {
                if (($overlay['type'] ?? null) !== 'drawing') {
                    continue;
                }

                $drawingPaths[] = $this->copyStorageFileToTemp(
                    (string) ($overlay['asset_disk'] ?? config('video.storage_disk', 's3')),
                    (string) $overlay['asset_key'],
                    'kulsah-edit-layer-',
                    '.png'
                );
            }

            $command = $this->buildCommand($sourcePath, $drawingPaths, $overlays, $outputPath);
            $process = new Process($command);
            $process->setTimeout((int) config('video.edit_render_timeout_seconds', 300));
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('FFmpeg edit render failed.', [
                    'video_id' => $video->id,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                throw new RuntimeException('Video edit render failed: '.trim($process->getErrorOutput()));
            }

            return $this->videoStorageService->uploadRenderedVideo(
                localPath: $outputPath,
                userId: (int) $video->user_id,
                originalName: pathinfo((string) $video->source_key, PATHINFO_FILENAME).'-edited.mp4'
            );
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);

            foreach ($drawingPaths as $drawingPath) {
                @unlink($drawingPath);
            }
        }
    }

    /**
     * @param  array<int, string>  $drawingPaths
     * @param  array<int, array<string, mixed>>  $overlays
     * @return array<int, string>
     */
    private function buildCommand(string $sourcePath, array $drawingPaths, array $overlays, string $outputPath): array
    {
        $command = ['ffmpeg', '-y', '-i', $sourcePath];

        foreach ($drawingPaths as $drawingPath) {
            array_push($command, '-loop', '1', '-i', $drawingPath);
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
     * @param  array<int, array<string, mixed>>  $overlays
     */
    private function buildFilterGraph(array $overlays): string
    {
        $filters = [];
        $current = '[0:v]';
        $step = 0;
        $drawingInput = 1;

        foreach ($overlays as $overlay) {
            $next = '[v'.$step.']';
            $enable = $this->enableExpression((float) ($overlay['start'] ?? 0), $overlay['end'] ?? null);

            if (($overlay['type'] ?? null) === 'text') {
                $drawText = [
                    'text='.$this->escapeFilterValue((string) $overlay['text']),
                    'x='.(int) ($overlay['x'] ?? 0),
                    'y='.(int) ($overlay['y'] ?? 0),
                    'fontsize='.(int) ($overlay['font_size'] ?? 42),
                    'fontcolor='.$this->escapeFilterValue((string) ($overlay['color'] ?? '#ffffff')),
                    "enable='{$enable}'",
                ];

                $fontFile = (string) config('video.ffmpeg_font_file', '');
                if ($fontFile !== '' && is_file($fontFile)) {
                    $drawText[] = 'fontfile='.$this->escapeFilterValue($fontFile);
                }

                if ((bool) ($overlay['box'] ?? true)) {
                    $drawText[] = 'box=1';
                    $drawText[] = 'boxcolor='.$this->escapeFilterValue((string) ($overlay['box_color'] ?? 'black@0.35'));
                    $drawText[] = 'boxborderw=12';
                }

                $filters[] = $current.'drawtext='.implode(':', $drawText).$next;
                $current = $next;
                $step++;
                continue;
            }

            $overlayInput = '['.$drawingInput.':v]';
            if (($overlay['width'] ?? null) || ($overlay['height'] ?? null)) {
                $scaled = '[layer'.$step.']';
                $width = $overlay['width'] ?? -1;
                $height = $overlay['height'] ?? -1;
                $filters[] = $overlayInput.'scale='.(int) $width.':'.(int) $height.$scaled;
                $overlayInput = $scaled;
            }

            $filters[] = $current.$overlayInput.'overlay='.(int) ($overlay['x'] ?? 0).':'.(int) ($overlay['y'] ?? 0).":enable='{$enable}'".$next;
            $current = $next;
            $step++;
            $drawingInput++;
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
