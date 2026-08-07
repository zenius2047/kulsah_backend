<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EventMediaService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
        private readonly CloudinaryService $cloudinaryService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function storeCoverImage(UploadedFile $file, User $user): array
    {
        $mimeType = (string) $file->getMimeType();

        if (! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Event cover images must be image files.');
        }

        $stored = $this->videoStorageService->uploadEventCoverImage($file, (int) $user->id);

        try {
            $cloudinary = $this->cloudinaryService->uploadImageFromS3Key($stored['source_key']);
        } catch (Throwable $throwable) {
            Storage::disk($stored['disk'])->delete($stored['source_key']);
            throw new RuntimeException('Unable to upload event cover image to Cloudinary: '.$throwable->getMessage(), previous: $throwable);
        }

        return [
            'disk' => $stored['disk'],
            'source_key' => $stored['source_key'],
            'source_url' => $stored['source_url'],
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'cloudinary_public_id' => $cloudinary['cloudinary_public_id'] ?? null,
            'cloudinary_asset_id' => $cloudinary['cloudinary_asset_id'] ?? null,
            'cover_image_url' => $cloudinary['rendered_url'] ?? $cloudinary['cdn_url'] ?? $stored['source_url'],
            'thumbnail_url' => $cloudinary['thumbnail_url'] ?? $cloudinary['poster_url'] ?? null,
            'metadata' => $cloudinary['metadata'] ?? [],
        ];
    }
}
