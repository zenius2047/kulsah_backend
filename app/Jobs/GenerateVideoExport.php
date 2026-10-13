<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoExport;
use App\Services\VideoExportRenderer;
use App\Services\VideoExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateVideoExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public array $backoff = [30, 90];

    public function __construct(public int $videoId, public string $token) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('video-export:'.$this->videoId))->shared()->releaseAfter(60)->expireAfter(1300)];
    }

    public function handle(VideoExportService $service, VideoExportRenderer $renderer): void
    {
        $export = VideoExport::query()->where('video_id', $this->videoId)->where('token', $this->token)->first();
        $video = Video::find($this->videoId);
        if (! $export || $export->status === 'ready') {
            return;
        }
        if (! $video || ! $service->eligible($video) || $service->fingerprint($video) !== $export->fingerprint) {
            $export->update(['status' => 'failed', 'error' => 'Video export eligibility changed.']);

            return;
        }
        $export->update(['status' => 'processing', 'error' => null]);
        $directory = sys_get_temp_dir().'/kulsah-export-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        $path = config('video.export_directory').'/'.$video->user_id.'/'.$video->id.'/'.$this->token.'.mp4';
        $stored = false;
        try {
            $renderer->render($video, $directory.'/export.mp4');
            $stream = fopen($directory.'/export.mp4', 'rb');
            try {
                $stored = Storage::disk($export->disk)->put($path, $stream, ['visibility' => 'private', 'ContentType' => 'video/mp4']);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $stored) {
                throw new \RuntimeException('Unable to store video export.');
            }
            $published = DB::transaction(function () use ($service, $export, $path): bool {
                $video = Video::query()->lockForUpdate()->find($this->videoId);
                $current = VideoExport::query()->whereKey($export->id)->lockForUpdate()->first();
                if (! $video || ! $current || $current->token !== $this->token
                    || ! $service->eligible($video) || $service->fingerprint($video) !== $current->fingerprint) {
                    return false;
                }
                $current->update(['status' => 'ready', 'path' => $path, 'error' => null]);

                return true;
            });
            if (! $published) {
                Storage::disk($export->disk)->delete($path);
                VideoExport::whereKey($export->id)->where('token', $this->token)->update(['status' => 'failed', 'error' => 'Video export eligibility changed.']);
            }
        } catch (Throwable $error) {
            if ($stored) {
                Storage::disk($export->disk)->delete($path);
            }
            VideoExport::whereKey($export->id)->where('token', $this->token)->update(['status' => 'pending']);
            Log::error('Video export processing failed.', ['video_id' => $this->videoId, 'exception' => $error]);
            throw $error;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function failed(?Throwable $error): void
    {
        VideoExport::where('video_id', $this->videoId)->where('token', $this->token)
            ->where('status', '!=', 'ready')->update(['status' => 'failed', 'error' => 'Video preparation failed. Please retry.']);
    }
}
