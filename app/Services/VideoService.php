<?php

namespace App\Services;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Notifications\VideoMentionedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly VideoInspectionService $videoInspectionService,
        private readonly VideoCaptionParserService $videoCaptionParserService,
        private readonly FastApiRecommendationService $fastApiRecommendationService,
        private readonly FeedService $feedService,
    ) {}

    public function createDraftVideo(array $data, int $userId): Video
    {
        $contentTypes = $this->normalizeContentTypes($data);
        $primaryContentType = $data['content_type'] ?? null;

        if (is_array($primaryContentType)) {
            $primaryContentType = $primaryContentType[0] ?? null;
        }

        $primaryContentType = $contentTypes[0] ?? $primaryContentType;

        $video = Video::create([
            'user_id' => $userId,
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'content_type' => $primaryContentType,
            'content_types' => $contentTypes,
            'visibility' => $data['visibility'] ?? 'public',
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'status' => 'draft',
            'progress_percentage' => 0,
            'metadata' => [
                'caption_hashtags' => [],
                'caption_mentions' => [],
                'mentioned_user_ids' => [],
            ],
        ]);

        tap($video, function (Video $video) use ($primaryContentType, $contentTypes): void {
            $video->update([
                'content_type' => $primaryContentType,
                'content_types' => $contentTypes,
            ]);
        });

        return $video->fresh();
    }

    public function uploadVideo(array $data, UploadedFile $file, int $userId, ?UploadedFile $thumbnailFile = null): Video
    {
        $stored = null;
        $thumbnail = null;

        Log::info('Video upload started.', [
            'stage' => 'inspect',
            'user_id' => $userId,
            'original_name' => $data['original_name'] ?? $file->getClientOriginalName(),
            'mime_type' => $data['mime_type'] ?? $file->getMimeType(),
            'size' => $data['size'] ?? $file->getSize(),
        ]);

        $duration = $this->videoInspectionService->getDurationSeconds($file->getPathname());
        $maxDuration = (int) config('video.max_duration_seconds', 120);

        if ($duration !== null && $duration > $maxDuration) {
            Log::warning('Video upload rejected because duration is too long.', [
                'stage' => 'inspect',
                'user_id' => $userId,
                'duration_seconds' => $duration,
                'max_duration_seconds' => $maxDuration,
            ]);

            throw ValidationException::withMessages([
                'video' => "Video duration must not exceed {$maxDuration} seconds.",
            ]);
        }

        Log::info('Video duration inspection completed.', [
            'stage' => 'inspect',
            'user_id' => $userId,
            'duration_seconds' => $duration,
        ]);

        $stored = $this->videoStorageService->uploadOriginal($file, $userId);
        $thumbnail = $this->uploadThumbnailIfProvided($thumbnailFile, $userId);

        Log::info('Video stored in primary storage.', [
            'stage' => 'storage',
            'user_id' => $userId,
            'disk' => $stored['disk'],
            'source_key' => $stored['source_key'],
        ]);

        $captionData = $this->videoCaptionParserService->parse($data['caption'] ?? null);
        $mentionedUsers = $this->videoCaptionParserService->resolveMentionedUsers($captionData['mentions']);

        try {
            $video = DB::transaction(function () use ($data, $stored, $thumbnail, $thumbnailFile, $userId, $duration, $captionData, $mentionedUsers) {
                $video = $this->createDraftVideo($data, $userId);

                $video->update(array_filter([
                    'source_url' => $stored['source_url'],
                    'source_key' => $stored['source_key'],
                    'thumbnail_url' => $thumbnail['source_url'] ?? ($data['thumbnail_url'] ?? null),
                    'progress_percentage' => 100,
                    'metadata' => array_merge($video->metadata ?? [], array_filter([
                        'storage_disk' => $stored['disk'],
                        'original_name' => $data['original_name'] ?? null,
                        'mime_type' => $data['mime_type'] ?? null,
                        'size' => $data['size'] ?? null,
                        'duration_seconds' => $duration,
                        'thumbnail_disk' => $thumbnail['disk'] ?? null,
                        'thumbnail_source_key' => $thumbnail['source_key'] ?? null,
                        'thumbnail_original_name' => $thumbnailFile?->getClientOriginalName(),
                        'thumbnail_mime_type' => $thumbnailFile?->getMimeType(),
                        'thumbnail_size' => $thumbnailFile?->getSize(),
                        'thumbnail_source' => $thumbnail ? 'user' : null,
                        'caption_hashtags' => $captionData['hashtags'],
                        'caption_mentions' => $captionData['mentions'],
                        'mentioned_user_ids' => $mentionedUsers->pluck('id')->values()->all(),
                    ], static fn ($value) => $value !== null)),
                ]));

                return $video->fresh();
            });
        } catch (Throwable $throwable) {
            Log::error('Video record creation failed after storage succeeded.', [
                'stage' => 'database',
                'user_id' => $userId,
                'source_key' => $stored['source_key'] ?? null,
                'error' => $throwable->getMessage(),
                'exception' => get_class($throwable),
            ]);

            if ($stored) {
                $this->videoStorageService->delete($stored['source_key'], $stored['disk']);
            }

            if ($thumbnail) {
                $this->videoStorageService->delete($thumbnail['source_key'], $thumbnail['disk']);
            }
            throw new RuntimeException('Failed to create the video record: '.$throwable->getMessage(), previous: $throwable);
        }

        $creator = User::query()->find($userId);
        if ($creator && $mentionedUsers->isNotEmpty()) {
            Notification::sendNow(
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

        Log::info('Video processing job dispatched.', [
            'stage' => 'queue',
            'user_id' => $userId,
            'video_id' => $video->id,
            'queue' => config('video.processing_queue', 'videos'),
        ]);

        return $video;
    }

    public function attachUploadedVideo(Video $video, UploadedFile $file, int $userId, ?UploadedFile $thumbnailFile = null): Video
    {
        $video = $video->fresh();
        $thumbnail = null;

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

        Log::info('Video re-upload started.', [
            'stage' => 'inspect',
            'user_id' => $userId,
            'video_id' => $video->id,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        $duration = $this->videoInspectionService->getDurationSeconds($file->getPathname());
        $maxDuration = (int) config('video.max_duration_seconds', 120);

        if ($duration !== null && $duration > $maxDuration) {
            Log::warning('Video re-upload rejected because duration is too long.', [
                'stage' => 'inspect',
                'user_id' => $userId,
                'video_id' => $video->id,
                'duration_seconds' => $duration,
                'max_duration_seconds' => $maxDuration,
            ]);

            throw ValidationException::withMessages([
                'video' => "Video duration must not exceed {$maxDuration} seconds.",
            ]);
        }

        Log::info('Video duration inspection completed for re-upload.', [
            'stage' => 'inspect',
            'user_id' => $userId,
            'video_id' => $video->id,
            'duration_seconds' => $duration,
        ]);

        $stored = $this->videoStorageService->uploadOriginal($file, $userId);
        $thumbnail = $this->uploadThumbnailIfProvided($thumbnailFile, $userId);

        Log::info('Video stored in primary storage for re-upload.', [
            'stage' => 'storage',
            'user_id' => $userId,
            'video_id' => $video->id,
            'disk' => $stored['disk'],
            'source_key' => $stored['source_key'],
        ]);

        try {
            $video->update([
                'source_url' => $stored['source_url'],
                'source_key' => $stored['source_key'],
                'thumbnail_url' => $thumbnail['source_url'] ?? $video->thumbnail_url,
                'status' => 'draft',
                'progress_percentage' => 100,
                'metadata' => array_merge($video->metadata ?? [], array_filter([
                    'storage_disk' => $stored['disk'],
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'duration_seconds' => $duration,
                    'thumbnail_disk' => $thumbnail['disk'] ?? null,
                    'thumbnail_source_key' => $thumbnail['source_key'] ?? null,
                    'thumbnail_original_name' => $thumbnailFile?->getClientOriginalName(),
                    'thumbnail_mime_type' => $thumbnailFile?->getMimeType(),
                    'thumbnail_size' => $thumbnailFile?->getSize(),
                    'thumbnail_source' => $thumbnail ? 'user' : null,
                ], static fn ($value) => $value !== null)),
            ]);
        } catch (Throwable $throwable) {
            $this->videoStorageService->delete($stored['source_key'], $stored['disk']);
            if ($thumbnail) {
                $this->videoStorageService->delete($thumbnail['source_key'], $thumbnail['disk']);
            }
            throw new RuntimeException('Failed to attach the uploaded video: '.$throwable->getMessage(), previous: $throwable);
        }

        ProcessVideoJob::dispatch($video->fresh())->onQueue(config('video.processing_queue', 'videos'));

        Log::info('Video processing job dispatched for re-upload.', [
            'stage' => 'queue',
            'user_id' => $userId,
            'video_id' => $video->id,
            'queue' => config('video.processing_queue', 'videos'),
        ]);

        return $video->fresh();
    }

    public function createDirectUploadSession(array $data, int $userId, ?UploadedFile $thumbnailFile = null): array
    {
        $contentTypes = $this->normalizeContentTypes($data);
        $primaryContentType = $data['content_type'] ?? null;

        if (is_array($primaryContentType)) {
            $primaryContentType = $primaryContentType[0] ?? null;
        }

        $primaryContentType = $contentTypes[0] ?? $primaryContentType;
        $thumbnail = $this->uploadThumbnailIfProvided($thumbnailFile, $userId);

        try {
            $upload = $this->videoStorageService->createTemporaryUpload(
                userId: $userId,
                originalName: $data['original_name'] ?? null,
                mimeType: $data['mime_type'] ?? null,
            );

            $video = Video::create([
                'user_id' => $userId,
                'title' => $data['title'] ?? null,
                'caption' => $data['caption'] ?? null,
                'content_type' => $primaryContentType,
                'content_types' => $contentTypes,
                'visibility' => $data['visibility'] ?? 'public',
                'thumbnail_url' => $thumbnail['source_url'] ?? null,
                'source_url' => $upload['source_url'],
                'source_key' => $upload['source_key'],
                'status' => 'draft',
                'progress_percentage' => 0,
                'metadata' => [
                    'upload_mode' => 'direct',
                    'upload_state' => 'awaiting_upload',
                    'storage_disk' => $upload['disk'],
                    'original_name' => $data['original_name'] ?? null,
                    'mime_type' => $data['mime_type'] ?? null,
                    'size' => $data['size'] ?? null,
                    'requires_editing' => (bool) ($data['requires_editing'] ?? false),
                    'thumbnail_disk' => $thumbnail['disk'] ?? null,
                    'thumbnail_source_key' => $thumbnail['source_key'] ?? null,
                    'thumbnail_original_name' => $thumbnailFile?->getClientOriginalName(),
                    'thumbnail_mime_type' => $thumbnailFile?->getMimeType(),
                    'thumbnail_size' => $thumbnailFile?->getSize(),
                    'thumbnail_source' => $thumbnail ? 'user' : null,
                ],
            ]);
        } catch (Throwable $throwable) {
            if ($thumbnail) {
                $this->videoStorageService->delete($thumbnail['source_key'], $thumbnail['disk']);
            }

            throw $throwable;
        }

        return [
            'video' => $video->fresh(),
            'upload' => $upload,
        ];
    }

    public function finalizeDirectUpload(Video $video, int $userId): Video
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

        if (! $video->source_key) {
            throw ValidationException::withMessages([
                'video' => 'The upload session is missing a source key.',
            ]);
        }

        $disk = data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));

        if (! Storage::disk($disk)->exists($video->source_key)) {
            throw ValidationException::withMessages([
                'video' => 'The uploaded file has not been received yet. Please finish the upload and try again.',
            ]);
        }

        $requiresEditing = (bool) data_get($video->metadata, 'requires_editing', false);

        $video->update([
            'status' => 'draft',
            'progress_percentage' => 100,
            'render_status' => $requiresEditing ? 'awaiting_edit' : $video->render_status,
            'metadata' => array_merge($video->metadata ?? [], [
                'upload_state' => 'uploaded',
                'upload_completed_at' => now()->toISOString(),
                'processing_state' => $requiresEditing ? 'awaiting_edit' : 'queued',
            ]),
        ]);

        if ($requiresEditing) {
            Log::info('Direct video upload completed and Cloudinary processing was deferred for editing.', [
                'video_id' => $video->id,
                'user_id' => $userId,
                'source_key' => $video->source_key,
            ]);

            return $video->fresh();
        }

        ProcessVideoJob::dispatch($video->fresh())->onQueue(config('video.processing_queue', 'videos'));

        return $video->fresh();
    }

    public function updateUploadProgress(Video $video, int $progressPercentage): Video
    {
        $video = $video->fresh();

        if (! $video) {
            throw ValidationException::withMessages([
                'video' => 'The selected video does not exist.',
            ]);
        }

        if (in_array($video->status, ['ready', 'failed'], true)) {
            throw ValidationException::withMessages([
                'video' => 'Upload progress can no longer be updated for this video.',
            ]);
        }

        Log::info('Video upload progress updated.', [
            'stage' => 'progress',
            'video_id' => $video->id,
            'progress_percentage' => $progressPercentage,
        ]);

        $video->update([
            'progress_percentage' => max(0, min(100, $progressPercentage)),
        ]);

        return $video->fresh();
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
                Notification::sendNow(
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

            VideoView::query()->create([
                'video_id' => $video->id,
                'user_id' => $viewerId,
                'viewed_at' => now(),
            ]);

            $this->fastApiRecommendationService->recordEvent(
                userId: $viewerId,
                eventType: 'watch',
                videoId: (int) $video->id,
                value: 1.0
            );
            $this->feedService->invalidateFeedCaches();
        }

        return $video->refresh();
    }

    private function normalizeContentTypes(array $data): array
    {
        $contentTypesInput = $data['content_types'] ?? null;

        if ($contentTypesInput === null || $contentTypesInput === []) {
            $contentTypesInput = $data['content_type'] ?? null;
        }

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

    private function uploadThumbnailIfProvided(?UploadedFile $thumbnailFile, int $userId): ?array
    {
        if (! $thumbnailFile) {
            return null;
        }

        return $this->videoStorageService->uploadThumbnail($thumbnailFile, $userId);
    }
}
