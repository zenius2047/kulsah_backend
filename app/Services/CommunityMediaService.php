<?php

namespace App\Services;

use App\Models\CommunityPostMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CommunityMediaService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly CloudinaryService $cloudinaryService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function storeMediaForPost(UploadedFile $file, User $user, int $sortOrder = 0): array
    {
        $mimeType = (string) $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');
        $isVideo = str_starts_with($mimeType, 'video/');

        if (! $isImage && ! $isVideo) {
            throw ValidationException::withMessages([
                'media' => 'Community media must be an image or video file.',
            ]);
        }

        $stored = $this->videoStorageService->uploadCommunityMedia($file, (int) $user->id);

        try {
            $cloudinary = $isImage
                ? $this->cloudinaryService->uploadImageFromS3Key($stored['source_key'])
                : $this->cloudinaryService->uploadVideoFromS3Key($stored['source_key']);
        } catch (Throwable $throwable) {
            Storage::disk($stored['disk'])->delete($stored['source_key']);
            throw new RuntimeException('Unable to upload community media to Cloudinary: '.$throwable->getMessage(), previous: $throwable);
        }

        return [
            'media_type' => $isImage ? 'image' : 'video',
            'disk' => $stored['disk'],
            'source_key' => $stored['source_key'],
            'source_url' => $stored['source_url'],
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'sort_order' => $sortOrder,
            'cloudinary_public_id' => $cloudinary['cloudinary_public_id'] ?? null,
            'cloudinary_asset_id' => $cloudinary['cloudinary_asset_id'] ?? null,
            'cloudinary_url' => $cloudinary['rendered_url'] ?? $cloudinary['cdn_url'] ?? null,
            'cloudinary_stream_url' => $cloudinary['streaming_url'] ?? $cloudinary['stream_url'] ?? null,
            'cloudinary_thumbnail_url' => $cloudinary['thumbnail_url'] ?? $cloudinary['poster_url'] ?? null,
            'metadata' => $cloudinary['metadata'] ?? [],
        ];
    }

    public function deleteStoredMedia(array $media): void
    {
        if (! empty($media['disk']) && ! empty($media['source_key'])) {
            Storage::disk((string) $media['disk'])->delete((string) $media['source_key']);
        }
    }
}
