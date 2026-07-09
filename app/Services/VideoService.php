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
        $contentTypes = $this->normalizeContentTypes($data);
        $primaryContentType = $contentTypes[0] ?? ($data['content_type'] ?? null);

        try {
            $video = DB::transaction(function () use ($data, $stored, $userId, $duration, $captionData, $mentionedUsers) {
                $contentTypes = $this->normalizeContentTypes($data);
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

    public function updateVideo(Video $video, array $data, int $userId): Video
    {
        $video = $video->fresh();

        if (! $video) {
            throw ValidationException::withMessages([
                'video' => 'The selected video does not exist.',
            ]);
        }

        if ((int) $video->user_id !== (int) $userId) {
            throw ValidationException::withMessages([
                'video' => 'You are not allowed to update this video.',
            ]);
        }

        $currentMetadata = is_array($video->metadata) ? $video->metadata : [];
        $updates = [];
        $mentionsToNotify = collect();
        $captionData = null;

        if (array_key_exists('title', $data)) {
            $updates['title'] = $data['title'];
        }

        if (array_key_exists('caption', $data)) {
            $updates['caption'] = $data['caption'];
            $captionData = $this->videoCaptionParserService->parse($data['caption'] ?? null);
            $mentionedUsers = $this->videoCaptionParserService->resolveMentionedUsers($captionData['mentions']);
            $existingMentionIds = collect(data_get($currentMetadata, 'mentioned_user_ids', []))->map(fn ($value) => (int) $value)->all();

            $mentionsToNotify = $mentionedUsers->reject(
                fn (User $mentionedUser) => in_array((int) $mentionedUser->id, $existingMentionIds, true)
            )->values();

            $updates['metadata'] = array_merge($currentMetadata, [
                'caption_hashtags' => $captionData['hashtags'],
                'caption_mentions' => $captionData['mentions'],
                'mentioned_user_ids' => $mentionedUsers->pluck('id')->values()->all(),
            ]);
        }

        if (array_key_exists('content_type', $data) || array_key_exists('content_types', $data)) {
            $contentTypes = $this->normalizeContentTypes($data);
            $updates['content_types'] = $contentTypes;
            $updates['content_type'] = $contentTypes[0] ?? null;
        }

        if (array_key_exists('visibility', $data)) {
            $updates['visibility'] = $data['visibility'] ?? 'public';
        }

        if ($updates !== []) {
            $video->update($updates);
        }

        if ($captionData && $mentionsToNotify->isNotEmpty()) {
            $actor = User::query()->find($userId);

            if ($actor) {
                Notification::send(
                    $mentionsToNotify,
                    new VideoMentionedNotification(
                        video: $video->fresh(),
                        actor: $actor,
                        mentions: $captionData['mentions'],
                        hashtags: $captionData['hashtags'],
                    )
                );
            }
        }

        return $video->fresh();
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

    private function normalizeContentTypes(array $data): array
    {
        $contentTypesInput = $data['content_types'] ?? ($data['content_type'] ?? null);

        if ($contentTypesInput === null) {
            return [];
        }

        $contentTypes = is_array($contentTypesInput)
            ? $contentTypesInput
            : preg_split('/\s*,\s*/', trim((string) $contentTypesInput), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            $contentTypes
        )));
    }
}
