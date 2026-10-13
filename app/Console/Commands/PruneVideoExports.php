<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\VideoExport;
use App\Services\VideoExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneVideoExports extends Command
{
    protected $signature = 'videos:prune-exports';

    protected $description = 'Remove private exports for deleted, restricted or changed videos.';

    public function handle(VideoExportService $service): int
    {
        VideoExport::query()->chunkById(100, function ($exports) use ($service): void {
            foreach ($exports as $export) {
                DB::transaction(function () use ($export, $service): void {
                    $video = Video::query()->lockForUpdate()->find($export->video_id);
                    $export = VideoExport::query()->lockForUpdate()->find($export->id);
                    if (! $export) {
                        return;
                    }
                    if (! $video || ! $service->eligible($video) || $service->fingerprint($video) !== $export->fingerprint) {
                        $service->deleteObject($export);
                        $export->delete();
                    }
                });
            }
        });

        return self::SUCCESS;
    }
}
