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
        [$disk, $path] = $this->buildUploadTarget($userId, $file->getClientOriginalName());

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

    public function createTemporaryUpload(int $userId, ?string $originalName = null, ?string $mimeType = null): array
    {
        [$disk, $path] = $this->buildUploadTarget($userId, $originalName);
        $ttlMinutes = max(1, (int) config('video.direct_upload_ttl_minutes', 15));

        $upload = Storage::disk($disk)->temporaryUploadUrl(
            $path,
            now()->addMinutes($ttlMinutes),
            array_filter([
                'ACL' => 'private',
                'ContentType' => $mimeType ?: null,
            ])
        );

        if (! is_array($upload) || ! isset($upload['url'], $upload['headers'])) {
            throw new RuntimeException('Unable to generate a temporary video upload URL.');
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

    /**
     * @return array{0: string, 1: string}
     */
    private function buildUploadTarget(int $userId, ?string $originalName = null): array
    {
        $disk = config('video.storage_disk', 's3');
        $directory = trim(config('video.upload_directory', 'videos/originals'), '/');

        $extension = pathinfo((string) $originalName, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? strtolower($extension) : 'mp4';

        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory."/{$userId}/{$filename}";

        return [$disk, $path];
    }
}
