<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class VideoInspectionService
{
    public function getDurationSeconds(string $path): ?float
    {
        if (! is_file($path)) {
            throw new RuntimeException("Video file not found at path: {$path}");
        }

        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        $process->setTimeout(20);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to inspect video duration: '.$process->getErrorOutput());
        }

        $duration = trim($process->getOutput());

        if ($duration === '') {
            return null;
        }

        return (float) $duration;
    }
}
