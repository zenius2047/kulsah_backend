<?php

use App\Models\User;
use App\Models\Video;
use App\Services\VideoExportRenderer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

// Run with: php tests/Support/verify-video-export-rendering.php
// Requires FFmpeg, ffprobe and the normal Laravel runtime (no database writes).
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$root = sys_get_temp_dir().'/kulsah-export-verify-'.bin2hex(random_bytes(8));
File::ensureDirectoryExists($root);
try {
    foreach (['portrait' => [360, 640], 'landscape' => [640, 360], 'square' => [480, 480]] as $shape => [$width, $height]) {
        $directory = $root.'/'.$shape;
        File::ensureDirectoryExists($directory);
        $source = $directory.'/source.mp4';
        $output = $directory.'/export.mp4';
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "color=c=gray:s={$width}x{$height}:r=24:d=11",
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100:duration=11', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $source]))->setTimeout(60)->mustRun();
        $originalHash = hash_file('sha256', $source);
        $video = new Video;
        // Include filter metacharacters to verify literal text handling.
        $video->setRelation('user', new User(['username' => "creator_%{pts}:quote'[]"]));
        app(VideoExportRenderer::class)->renderLocal($video, $source, $output);
        if (hash_file('sha256', $source) !== $originalHash) {
            throw new RuntimeException('Source was modified.');
        }
        if (str_replace("\n", '', file_get_contents($directory.'/handle.txt')) !== "@creator_%{pts}:quote'[]") {
            throw new RuntimeException('Wrong creator handle.');
        }
        $probe = new Process(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $output]);
        $probe->mustRun();
        $metadata = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $v = array_values(array_filter($metadata['streams'], fn ($stream) => $stream['codec_type'] === 'video'))[0];
        $a = array_values(array_filter($metadata['streams'], fn ($stream) => $stream['codec_type'] === 'audio'))[0];
        if ($v['width'] !== $width || $v['height'] !== $height || $v['pix_fmt'] !== 'yuv420p'
            || $v['codec_name'] !== 'h264' || $a['codec_name'] !== 'aac'
            || abs((float) $v['duration'] - (float) $a['duration']) > .15) {
            throw new RuntimeException('Invalid dimensions/codecs or audio synchronization.');
        }
        $bytes = file_get_contents($output);
        if (strpos($bytes, 'moov') > strpos($bytes, 'mdat')) {
            throw new RuntimeException('Fast-start is missing.');
        }
        foreach ([1, 6] as $time) {
            (new Process(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $time, '-i', $output, '-frames:v', '1', $directory.'/frame-'.$time.'.png']))->mustRun();
        }
        echo $shape.': '.$width.'x'.$height.' H.264/AAC, synchronized audio, fast-start, original unchanged'.PHP_EOL;
    }
    // Preserve QA frames for visual inspection when explicitly requested.
    if (getenv('VIDEO_EXPORT_QA_DIRECTORY')) {
        File::copyDirectory($root, getenv('VIDEO_EXPORT_QA_DIRECTORY'));
    }
} finally {
    File::deleteDirectory($root);
}
