<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DuetVideoRenderingService
{
    public function __construct(
        private readonly CloudinaryService $cloudinaryService,
    ) {}

    /**
     * Render the source and response into one deterministic media file, then
     * publish that file through the normal Cloudinary delivery pipeline.
     *
     * @return array<string, mixed>
     */
    public function renderAndUpload(Video $response): array
    {
        $outputPath = $this->renderToLocal($response);

        try {
            return $this->cloudinaryService->uploadVideoFromLocalPath(
                localPath: $outputPath,
                originalName: 'duet-'.$response->id.'.mp4',
            );
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * The caller owns the returned temporary file and must remove it.
     */
    public function renderToLocal(Video $response): string
    {
        $response = $response->fresh();
        $source = $response?->duetSourceVideo()->first();

        if (! $response || ! $source || ! $response->source_key || ! $source->source_key) {
            throw new RuntimeException('The duet source or response video is unavailable.');
        }

        $sourcePath = $this->copyVideoToTemp($source, 'kulsah-duet-source-');
        $responsePath = $this->copyVideoToTemp($response, 'kulsah-duet-response-');
        $outputPath = $this->makeTempPath('kulsah-duet-render-', '.mp4');

        try {
            $metadata = is_array($response->metadata) ? $response->metadata : [];
            $layout = (string) data_get($metadata, 'duet_layout', 'side_by_side');
            $sourceAudioEnabled = (bool) data_get($metadata, 'duet_source_audio', true);
            $responseAudioEnabled = (bool) data_get($metadata, 'duet_response_audio', true);
            $filterGraph = $this->buildFilterGraph(
                layout: $layout,
                sourceHasAudio: $sourceAudioEnabled && $this->hasAudio($sourcePath),
                responseHasAudio: $responseAudioEnabled && $this->hasAudio($responsePath),
            );

            $command = [
                'ffmpeg', '-y',
                '-i', $sourcePath,
                '-i', $responsePath,
                '-filter_complex', $filterGraph['filter'],
                '-map', '[vout]',
            ];

            if ($filterGraph['audio_map'] !== null) {
                array_push($command, '-map', $filterGraph['audio_map']);
            } else {
                $command[] = '-an';
            }

            array_push(
                $command,
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-preset', (string) config('video.duet_render_preset', 'veryfast'),
                '-crf', (string) max(0, min(51, (int) config('video.duet_render_crf', 25))),
                '-r', '30',
                '-c:a', 'aac',
                '-b:a', (string) config('video.transcode_audio_bitrate', '128k'),
                '-movflags', '+faststart',
                '-shortest',
                $outputPath,
            );

            $process = new Process($command);
            $process->setTimeout((int) config('video.duet_render_timeout_seconds', 900));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) <= 0) {
                throw new RuntimeException('Duet rendering failed: '.trim($process->getErrorOutput()));
            }

            return $outputPath;
        } catch (\Throwable $throwable) {
            @unlink($outputPath);
            throw $throwable;
        } finally {
            @unlink($sourcePath);
            @unlink($responsePath);
        }
    }

    /**
     * @return array{filter:string,audio_map:?string}
     */
    public function buildFilterGraph(string $layout, bool $sourceHasAudio, bool $responseHasAudio): array
    {
        $layout = in_array($layout, ['side_by_side', 'stacked', 'picture_in_picture'], true)
            ? $layout
            : 'side_by_side';

        if ($layout === 'stacked') {
            $videoFilter = '[0:v]setpts=PTS-STARTPTS,scale=720:640:force_original_aspect_ratio=increase,crop=720:640[source];'
                .'[1:v]setpts=PTS-STARTPTS,scale=720:640:force_original_aspect_ratio=increase,crop=720:640[response];'
                .'[source][response]vstack=inputs=2[vout]';
        } elseif ($layout === 'picture_in_picture') {
            $videoFilter = '[0:v]setpts=PTS-STARTPTS,scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280[source];'
                .'[1:v]setpts=PTS-STARTPTS,scale=260:360:force_original_aspect_ratio=increase,crop=260:360[response];'
                .'[source][response]overlay=442:740:eof_action=pass[vout]';
        } else {
            $videoFilter = '[0:v]setpts=PTS-STARTPTS,scale=360:1280:force_original_aspect_ratio=decrease,'
                .'pad=360:1280:(ow-iw)/2:(oh-ih)/2:color=black[source];'
                .'[1:v]setpts=PTS-STARTPTS,scale=360:1280:force_original_aspect_ratio=decrease,'
                .'pad=360:1280:(ow-iw)/2:(oh-ih)/2:color=black[response];'
                .'[source][response]hstack=inputs=2[vout]';
        }

        $audioFilters = [];
        $audioInputs = [];

        if ($sourceHasAudio) {
            $audioFilters[] = '[0:a]asetpts=PTS-STARTPTS,volume=1[source_audio]';
            $audioInputs[] = '[source_audio]';
        }
        if ($responseHasAudio) {
            $audioFilters[] = '[1:a]asetpts=PTS-STARTPTS,volume=1[response_audio]';
            $audioInputs[] = '[response_audio]';
        }

        $audioMap = null;
        if (count($audioInputs) === 2) {
            $audioFilters[] = implode('', $audioInputs).'amix=inputs=2:duration=shortest:dropout_transition=0,'
                .'aresample=async=1:first_pts=0[aout]';
            $audioMap = '[aout]';
        } elseif (count($audioInputs) === 1) {
            $audioFilters[] = $audioInputs[0].'aresample=async=1:first_pts=0[aout]';
            $audioMap = '[aout]';
        }

        return [
            'filter' => implode(';', array_merge([$videoFilter], $audioFilters)),
            'audio_map' => $audioMap,
        ];
    }

    private function copyVideoToTemp(Video $video, string $prefix): string
    {
        $disk = $video->source_disk ?: data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));
        $stream = Storage::disk($disk)->readStream((string) $video->source_key);

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read a duet video from primary storage.');
        }

        $tempPath = $this->makeTempPath($prefix, '.mp4');
        $target = fopen($tempPath, 'w+b');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create a temporary duet video.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $tempPath;
    }

    private function hasAudio(string $path): bool
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'stream=index', '-of', 'csv=p=0', $path,
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    private function makeTempPath(string $prefix, string $extension): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$prefix.Str::uuid()->toString().$extension;
    }
}
