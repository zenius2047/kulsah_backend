<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoExport;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoExportConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    public function test_simultaneous_processes_queue_only_one_export(): void
    {
        $video = Video::factory()->create(['metadata' => ['allow_download' => true]]);
        $viewer = User::factory()->create();
        $directory = sys_get_temp_dir().'/kulsah-concurrency-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/request-video-export.php'),
                    (string) $video->id, (string) $viewer->id, $directory.'/go', $directory.'/ready-'.$index,
                ], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
                $process->setTimeout(240)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 180;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Concurrent request process exited before the barrier: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Concurrent request processes did not become ready.');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0]['token'], $results[1]['token']);
            $this->assertSame(1, $results[0]['jobs'] + $results[1]['jobs']);
            $this->assertDatabaseCount('video_exports', 1);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            VideoExport::where('video_id', $video->id)->delete();
            $video->delete();
            User::whereKey($viewer->id)->delete();
            User::whereKey($video->user_id)->delete();
            File::deleteDirectory($directory);
        }
    }
}
