<?php

use App\Jobs\GenerateVideoExport;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoExportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'testing'
    || config('database.connections.testing.database') !== 'kulsah_testing') {
    throw new RuntimeException('Concurrent export checks require the test database.');
}
Queue::fake();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 180;
while (! is_file($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Timed out waiting for concurrency barrier.');
    }
    usleep(10000);
}
$export = DB::transaction(function () use ($argv) {
    $export = app(VideoExportService::class)->request(
        Video::findOrFail($argv[1]), User::findOrFail($argv[2]),
    );
    // Keep the row locked long enough for the other process to contend for it.
    usleep(300000);

    return $export;
});
echo json_encode(['token' => $export->token, 'jobs' => Queue::pushed(GenerateVideoExport::class)->count()], JSON_THROW_ON_ERROR);
