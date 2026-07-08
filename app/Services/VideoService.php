<?php

namespace App\Services;

use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use App\Models\User;
use App\Notifications\VideoMentionedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly VideoInspectionService $videoInspectionService,
        private readonly VideoCaptionParserService $videoCaptionParserService,
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
        $captionData = $this->videoCaptionParserService->parse($data['caption'] ?? null);
        $mentionedUsers = $this->videoCaptionParserService->resolveMentionedUsers($captionData['mentions']);

        try {
            $video = DB::transaction(function () use ($data, $stored, $userId, $duration, $captionData, $mentionedUsers) {
                $contentTypes = array_values(array_filter(array_map(
                    fn ($value) => is_string($value) ? trim($value) : '',
                    is_array($data['content_types'] ?? null)
                        ? $data['content_types']
                        : [$data['content_types'] ?? ($data['content_type'] ?? null)]
                )));
                $primaryContentType = $contentTypes[0] ?? ($data['content_type'] ?? null);

                return Video::create([
                    'user_id' => $userId,
                    'title' => $data['title'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'content_type' => $primaryContentType,
                    'content_types' => $contentTypes,
                    'visibility' => $data['visibility'] ?? 'public',
                    'source_url' => $stored['source_url'],
                    'source_key' => $stored['source_key'],
                    'status' => 'processing',
                    'progress_percentage' => 25,
                    'metadata' => [
                        'storage_disk' => $stored['disk'],
                        'original_name' => $data['original_name'] ?? null,
                        'mime_type' => $data['mime_type'] ?? null,
                        'size' => $data['size'] ?? null,
                        'duration_seconds' => $duration,
                        'caption_hashtags' => $captionData['hashtags'],
                        'caption_mentions' => $captionData['mentions'],
                        'mentioned_user_ids' => $mentionedUsers->pluck('id')->values()->all(),
                    ],
                ]);
            });
        } catch (Throwable $throwable) {
            $this->videoStorageService->delete($stored['source_key'], $stored['disk']);
            throw new RuntimeException('Failed to create the video record: '.$throwable->getMessage(), previous: $throwable);
        }

        $creator = User::query()->find($userId);
        if ($creator && $mentionedUsers->isNotEmpty()) {
            Notification::send(
                $mentionedUsers,
                new VideoMentionedNotification(
                    video: $video->fresh(),
                    actor: $creator,
                    mentions: $captionData['mentions'],
                    hashtags: $captionData['hashtags'],
                )
            );
        }

        ProcessVideoJob::dispatch($video)->onQueue(config('video.processing_queue', 'videos'));

        return $video;
    }

    public function recordView(Video $video, int $viewerId): Video
    {
        $cooldownMinutes = max(1, (int) config('video.view_cooldown_minutes', 60));
        $cacheKey = "video:viewed:{$video->id}:{$viewerId}";

        if (Cache::add($cacheKey, true, now()->addMinutes($cooldownMinutes))) {
            $video->increment('views_count');
        }

        return $video->refresh();
    }
}
