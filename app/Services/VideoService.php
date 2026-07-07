<?php

namespace App\Services;

use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly VideoInspectionService $videoInspectionService,
    ) {
    }

    public function uploadVideo(array $data, UploadedFile $file, int $userId): Video
    {
        $duration = $this->videoInspectionService->getDurationSeconds($file->getPathname());
        $maxDuration = (int) config('video.max_duration_seconds', 120);

        if ($duration !== null && $duration > $maxDuration) {
            throw ValidationException::withMessages([
                'video' => "Video duration must not exceed {$maxDuration} seconds.",
            ]);
        }

        $stored = $this->videoStorageService->uploadOriginal($file, $userId);

        try {
            $video = DB::transaction(function () use ($data, $stored, $userId, $duration) {
                return Video::create([
                    'user_id' => $userId,
                    'title' => $data['title'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'visibility' => $data['visibility'] ?? 'public',
                    'source_url' => $stored['source_url'],
                    'source_key' => $stored['source_key'],
                    'status' => 'processing',
                    'metadata' => [
                        'storage_disk' => $stored['disk'],
                        'original_name' => $data['original_name'] ?? null,
                        'mime_type' => $data['mime_type'] ?? null,
                        'size' => $data['size'] ?? null,
                        'duration_seconds' => $duration,
                    ],
                ]);
            });
        } catch (Throwable $throwable) {
            $this->videoStorageService->delete($stored['source_key'], $stored['disk']);
            throw new RuntimeException('Failed to create the video record: '.$throwable->getMessage(), previous: $throwable);
        }

        ProcessVideoJob::dispatch($video)->onQueue(config('video.processing_queue', 'videos'));

        return $video;
    }
}
