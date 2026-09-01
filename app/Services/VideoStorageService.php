<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class VideoStorageService
{
    public function uploadOriginal(UploadedFile $file, int $userId): array
    {
        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName(), config('video.upload_directory', 'videos/originals'));

        $storedPath = Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private']
        );

        if (! $storedPath) {
            throw new RuntimeException('Unable to store the uploaded video in primary storage.');
        }

        return [
            'disk' => $disk,
            'source_key' => $storedPath,
            'source_url' => Storage::disk($disk)->url($storedPath),
        ];
    }

    public function uploadThumbnail(UploadedFile $file, int $userId): array
    {
        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName(), config('video.thumbnail_directory', 'videos/thumbnails'));

        $storedPath = Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'public']
        );

        if (! $storedPath) {
            throw new RuntimeException('Unable to store the uploaded thumbnail in primary storage.');
        }

        return [
            'disk' => $disk,
            'source_key' => $storedPath,
            'source_url' => Storage::disk($disk)->url($storedPath),
        ];
    }

    public function uploadEditAsset(UploadedFile $file, int $userId): array
    {
        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName(), config('video.edit_asset_directory', 'videos/edit-assets'));

        $storedPath = Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private']
        );

        if (! $storedPath) {
            throw new RuntimeException('Unable to store the video edit asset in primary storage.');
        }

        return [
            'disk' => $disk,
            'source_key' => $storedPath,
            'source_url' => Storage::disk($disk)->url($storedPath),
        ];
    }

    public function uploadCommunityMedia(UploadedFile $file, int $userId): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The uploaded community media file is not valid.');
        }

        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName(), config('video.community_media_directory', 'community/media'));
        $directory = dirname($path);

        // S3 buckets may reject ACL-based visibility settings, so we rely on the disk's default permissions.
        $storedPath = Storage::disk($disk)->putFileAs($directory, $file, basename($path));

        if (! $storedPath) {
            throw new RuntimeException(sprintf(
                'Unable to store the community media in primary storage. disk=%s path=%s mime=%s size=%s',
                $disk,
                $path,
                (string) $file->getMimeType(),
                (string) $file->getSize(),
            ));
        }

        return [
            'disk' => $disk,
            'source_key' => $storedPath,
            'source_url' => Storage::disk($disk)->url($storedPath),
        ];
    }

    public function uploadEventCoverImage(UploadedFile $file, int $userId): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The uploaded event cover image is not valid.');
        }

        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName(), config('video.event_cover_directory', 'events/covers'));

        $storedPath = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        if (! $storedPath) {
            throw new RuntimeException('Unable to store the event cover image in primary storage.');
        }

        return [
            'disk' => $disk,
            'source_key' => $storedPath,
            'source_url' => Storage::disk($disk)->url($storedPath),
        ];
    }

    public function uploadRenderedVideo(string $localPath, int $userId, string $originalName = 'edited.mp4'): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('Rendered video file is missing.');
        }

        [$disk, $path] = $this->buildUploadTarget($userId, $originalName, config('video.rendered_directory', 'videos/rendered'));
        $stream = fopen($localPath, 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the rendered video for storage.');
        }

        try {
            $stored = Storage::disk($disk)->put($path, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }

        if (! $stored) {
            throw new RuntimeException('Unable to store the rendered video in primary storage.');
        }

        return [
            'disk' => $disk,
            'source_key' => $path,
            'source_url' => Storage::disk($disk)->url($path),
        ];
    }

    public function createTemporaryUpload(int $userId, ?string $originalName = null, ?string $mimeType = null): array
    {
        return $this->createTemporaryUploadInDirectory(
            userId: $userId,
            originalName: $originalName,
            mimeType: $mimeType,
            directory: config('video.upload_directory', 'videos/originals')
        );
    }

    public function createTemporaryUploadInDirectory(int $userId, ?string $originalName = null, ?string $mimeType = null, ?string $directory = null, string $visibility = 'private'): array
    {
        [$disk, $path] = $this->buildUploadTarget($userId, $originalName, $directory);
        $ttlMinutes = max(1, (int) config('video.direct_upload_ttl_minutes', 15));

        $upload = Storage::disk($disk)->temporaryUploadUrl(
            $path,
            now()->addMinutes($ttlMinutes),
            array_filter([
                'ACL' => $visibility === 'public' ? 'public-read' : 'private',
                'ContentType' => $mimeType ?: null,
            ])
        );

        if (! is_array($upload) || ! isset($upload['url'], $upload['headers'])) {
            throw new RuntimeException('Unable to generate a temporary upload URL.');
        }

        return [
            'disk' => $disk,
            'source_key' => $path,
            'source_url' => Storage::disk($disk)->url($path),
            'upload_url' => $upload['url'],
            'upload_headers' => $upload['headers'],
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
        ];
    }

    public function delete(string $sourceKey, ?string $disk = null): bool
    {
        return Storage::disk($disk ?: config('video.storage_disk', 's3'))->delete($sourceKey);
    }

    public function resolveAccessibleUrl(string $disk, string $sourceKey): string
    {
        $storage = Storage::disk($disk);

        try {
            if (method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl($sourceKey, now()->addMinutes(15));
            }
        } catch (\Throwable) {
            // Fall back to the regular URL when the disk does not support signed URLs.
        }

        return $storage->url($sourceKey);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildUploadTarget(int $userId, ?string $originalName = null, ?string $directory = null): array
    {
        $disk = config('video.storage_disk', 's3');
        $directory = trim((string) ($directory ?: config('video.upload_directory', 'videos/originals')), '/');

        $extension = pathinfo((string) $originalName, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? strtolower($extension) : 'mp4';

        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory."/{$userId}/{$filename}";

        return [$disk, $path];
    }
}

