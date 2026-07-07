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
        $disk = config('video.storage_disk', 's3');
        $directory = trim(config('video.upload_directory', 'videos/originals'), '/');

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'mp4';
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory."/{$userId}/{$filename}";

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

    public function delete(string $sourceKey, ?string $disk = null): bool
    {
        return Storage::disk($disk ?: config('video.storage_disk', 's3'))->delete($sourceKey);
    }
}
