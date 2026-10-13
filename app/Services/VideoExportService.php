<?php

namespace App\Services;

use App\Jobs\GenerateVideoExport;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExportService
{
    public function publicPreviewAllowed(Video $video): bool
    {
        $creator = $video->user()->first();

        return $creator !== null
            && $creator->console_status === 'Active'
            && $video->status === 'ready'
            && $video->processing_status?->value === 'ready'
            && $video->visibility === 'public'
            && ! (bool) data_get($video->metadata, 'is_premium', false)
            && in_array(data_get($video->metadata, 'console_status', 'Approved'), ['Approved', 'Published', 'Active'], true);
    }

    public function eligible(Video $video): bool
    {
        return $this->publicPreviewAllowed($video)
            && (bool) data_get($video->metadata, 'allow_download', false)
            && trim((string) $video->user()->value('username')) !== '';
    }

    public function authorize(Video $video, User $viewer): void
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer && $viewer->console_status === 'Active' && $this->eligible($video), 403, 'This video cannot be exported.');
        $creator = $video->user()->firstOrFail();
        abort_if($viewer->isBlocking($creator) || $viewer->isBlockedBy($creator), 403, 'This video cannot be exported.');
    }

    public function fingerprint(Video $video): string
    {
        $logo = (string) config('video.export_logo');

        return hash('sha256', json_encode([
            $video->fallback_mp4_url, $video->rendered_url, $video->playback_url,
            $video->source_key, $video->cloudinary_asset_id,
            $video->processed_at?->toISOString(), $video->render_completed_at?->toISOString(),
            data_get($video->metadata, 'edit_render_token'),
            $video->user()->value('username'), config('video.export_version'),
            is_file($logo) ? hash_file('sha256', $logo) : null,
        ], JSON_THROW_ON_ERROR));
    }

    public function request(Video $video, User $viewer, bool $retry = false): VideoExport
    {
        return DB::transaction(function () use ($video, $viewer, $retry): VideoExport {
            // Serialize the initial insert as well as concurrent export requests.
            $video = Video::query()->lockForUpdate()->findOrFail($video->id);
            $this->authorize($video, $viewer);
            $fingerprint = $this->fingerprint($video);
            $export = VideoExport::query()->where('video_id', $video->id)->lockForUpdate()->first();
            if ($export && $export->fingerprint === $fingerprint
                && ! ($retry && $export->status === 'failed')
                && ! (in_array($export->status, ['pending', 'processing'], true) && $export->updated_at->lt(now()->subHour()))) {
                if ($export->status !== 'ready' || Storage::disk($export->disk)->exists($export->path)) {
                    return $export;
                }
            }
            if ($export?->path) {
                $this->deleteObject($export);
            }
            $export ??= new VideoExport(['video_id' => $video->id]);
            $token = (string) Str::uuid();
            $export->fill([
                'fingerprint' => $fingerprint, 'token' => $token, 'status' => 'pending',
                'disk' => config('video.storage_disk'),
                'path' => config('video.export_directory').'/'.$video->user_id.'/'.$video->id.'/'.$token.'.mp4',
                'error' => null,
            ])->save();
            GenerateVideoExport::dispatch($video->id, $export->token)
                ->onQueue(config('video.processing_queue', 'videos'))->afterCommit();

            return $export;
        });
    }

    public function deleteObject(VideoExport $export): void
    {
        if ($export->path && ! Storage::disk($export->disk)->delete($export->path)) {
            throw new \RuntimeException('Unable to remove superseded video export.');
        }
    }
}
