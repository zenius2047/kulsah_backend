<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class VideoExportRenderer
{
    public function render(Video $video, string $output): void
    {
        $directory = dirname($output);
        $source = $directory.'/source.mp4';
        $logo = (string) config('video.export_logo');
        if (! is_file($logo)) {
            throw new RuntimeException('The official Kulsah logo is missing.');
        }
        // Only fetch this project's Cloudinary delivery host/account. Never pass
        // user-controlled network URLs to FFmpeg (including nested playlists).
        $url = $video->fallback_mp4_url ?: $video->rendered_url;
        $cloud = (string) config('services.cloudinary.cloud_name');
        $parts = parse_url((string) $url);
        if ($cloud === '' || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'res.cloudinary.com'
            || ! str_starts_with($parts['path'] ?? '', '/'.$cloud.'/video/upload/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('A trusted final processed MP4 is required for export.');
        }
        $response = Http::withOptions(['sink' => $source, 'allow_redirects' => false])
            ->connectTimeout(10)->timeout(120)->get($url);
        if (! $response->successful() || ! is_file($source) || filesize($source) === 0) {
            throw new RuntimeException('Unable to fetch the final processed MP4.');
        }
        $this->renderLocal($video, $source, $output);
    }

    public function renderLocal(Video $video, string $source, string $output): void
    {
        $directory = dirname($output);
        $handle = $directory.'/handle.txt';
        $logo = (string) config('video.export_logo');
        if (! is_file($logo)) {
            throw new RuntimeException('The official Kulsah logo is missing.');
        }
        $handleText = '@'.ltrim((string) $video->user->username, '@');
        $probe = new Process(['ffprobe', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-select_streams', 'v:0', '-show_entries', 'stream=width,height,sample_aspect_ratio', '-of', 'json', $source]);
        $probe->setTimeout(30)->mustRun();
        $size = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR)['streams'][0] ?? [];
        $width = (int) ($size['width'] ?? 0);
        $height = (int) ($size['height'] ?? 0);
        $sar = explode(':', (string) ($size['sample_aspect_ratio'] ?? '1:1'));
        if (count($sar) === 2 && (float) $sar[0] > 0 && (float) $sar[1] > 0) {
            $width = (int) round($width * (float) $sar[0] / (float) $sar[1]);
        }
        if ($width < 32 || $height < 32) {
            throw new RuntimeException('Processed video dimensions are invalid.');
        }
        // Numeric dimensions only; handles are literal UTF-8 text files, with
        // expansion disabled. Paths never enter a filter expression.
        $small = min($width, $height);
        $margin = max(6, (int) round($small * .035));
        $logoSize = max(20, (int) round($small * .11));
        $font = max(12, (int) round($small * .035));
        // Wrap exceptionally long usernames instead of reducing them to
        // unreadable text or allowing them to run beyond the safe margins.
        $charactersPerLine = max(1, (int) floor(($width - 2 * $margin - 8) / ($font * 1.05)));
        $lines = mb_str_split($handleText, $charactersPerLine);
        $lineSpacing = max(1, (int) round($font * .15));
        $textHeight = (int) ceil(count($lines) * $font * 1.3);
        file_put_contents($handle, implode("\n", $lines));
        $alternate = 'lt(mod(t,10),5)';
        $filter = '[0:v]scale=trunc(iw*sar/2)*2:trunc(ih/2)*2,setsar=1[base];'
            ."[1:v]scale={$logoSize}:{$logoSize}[logo];"
            ."[base][logo]overlay=x='if({$alternate},{$margin},W-w-{$margin})':y='if({$alternate},{$margin},H-h-{$margin}-{$textHeight}-8)':eof_action=repeat[marked];"
            ."[marked]drawtext=textfile=handle.txt:expansion=none:fontcolor=white:fontsize={$font}:line_spacing={$lineSpacing}:box=1:boxcolor=black@0.45:boxborderw=4:"
            ."x='if({$alternate},{$margin},w-text_w-{$margin})':y='if({$alternate},{$margin}+{$logoSize}+8,h-text_h-{$margin})'[out]";
        $fontFile = config('video.ffmpeg_font_file');
        if ($fontFile) {
            if (! is_file($fontFile)) {
                throw new RuntimeException('Configured FFmpeg font is missing.');
            }
            copy($fontFile, $directory.'/font.ttf');
            $filter = str_replace('drawtext=', 'drawtext=fontfile=font.ttf:', $filter);
        }
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-protocol_whitelist', 'file,pipe', '-i', $source,
            '-i', $logo, '-filter_complex', $filter, '-map', '[out]', '-map', '0:a?',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-map_metadata', '-1', $output,
        ], $directory);
        $process->setTimeout(900);
        $process->mustRun();
        if (! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('FFmpeg produced an empty export.');
        }
    }
}
