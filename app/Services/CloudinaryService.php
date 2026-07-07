<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class CloudinaryService
{
    public function uploadVideoFromS3Key(string $sourceKey): array
    {
        $disk = config('video.storage_disk', 's3');
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $folder = trim((string) config('services.cloudinary.folder', 'kulsah/videos'), '/');

        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            throw new RuntimeException('Cloudinary credentials are not configured.');
        }

        $sourceStream = Storage::disk($disk)->readStream($sourceKey);

        if (! is_resource($sourceStream)) {
            throw new RuntimeException('Unable to read the source video from primary storage.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'kulsah-video-');

        if ($tempPath === false) {
            fclose($sourceStream);
            throw new RuntimeException('Unable to allocate a temporary file for Cloudinary upload.');
        }

        try {
            $tempFile = fopen($tempPath, 'w+b');

            if ($tempFile === false) {
                fclose($sourceStream);
                throw new RuntimeException('Unable to open a temporary file for Cloudinary upload.');
            }

            stream_copy_to_stream($sourceStream, $tempFile);
            fclose($sourceStream);
            fclose($tempFile);

            $uploadPath = $tempPath;
            $transcodeFailed = false;

            if (filter_var(config('video.transcode_enabled', true), FILTER_VALIDATE_BOOL)) {
                try {
                    $uploadPath = $this->transcodeForDelivery($tempPath);
                } catch (RuntimeException $exception) {
                    $transcodeFailed = true;

                    Log::warning('Video transcoding failed; falling back to original file.', [
                        'source_key' => $sourceKey,
                        'message' => $exception->getMessage(),
                    ]);

                    $uploadPath = $tempPath;
                }
            }

            $publicId = $this->buildPublicId($sourceKey);
            $timestamp = time();
            $params = [
                'folder' => $folder,
                'public_id' => $publicId,
                'overwrite' => 'true',
                'unique_filename' => 'false',
                'use_filename' => 'false',
                'timestamp' => $timestamp,
            ];
            $signature = $this->buildSignature($params, $apiSecret);
            $uploadUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/video/upload";

            $postFields = [
            'file' => new \CURLFile($uploadPath, 'video/mp4', basename($sourceKey)),
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'public_id' => $publicId,
                'overwrite' => 'true',
                'unique_filename' => 'false',
                'use_filename' => 'false',
                'signature' => $signature,
            'resource_type' => 'video',
        ];

            if ($transcodeFailed) {
                $postFields['context'] = 'transcode_fallback=true';
            }

            $response = $this->postMultipart($uploadUrl, $postFields);
        } finally {
            @unlink($tempPath);
            if (isset($uploadPath) && $uploadPath !== $tempPath) {
                @unlink($uploadPath);
            }
        }

        if (isset($response['error'])) {
            $message = is_array($response['error']) ? ($response['error']['message'] ?? 'Cloudinary upload failed.') : (string) $response['error'];
            throw new RuntimeException($message);
        }

        if (! isset($response['secure_url'], $response['public_id'])) {
            throw new RuntimeException('Cloudinary did not return a valid video response.');
        }

        return [
            'cdn_url' => $response['secure_url'],
            'cloudinary_public_id' => $response['public_id'],
            'thumbnail_url' => $this->generateThumbnailUrl($response['public_id']),
            'duration' => isset($response['duration']) ? (int) round((float) $response['duration']) : null,
            'metadata' => $response,
        ];
    }

    public function generateOptimizedUrl(string $publicId): string
    {
        $cloudName = config('services.cloudinary.cloud_name');

        return "https://res.cloudinary.com/{$cloudName}/video/upload/f_auto,q_auto/{$publicId}";
    }

    public function generateThumbnailUrl(string $publicId): string
    {
        $cloudName = config('services.cloudinary.cloud_name');

        return "https://res.cloudinary.com/{$cloudName}/video/upload/so_0,w_720,h_1280,c_fill,f_jpg,q_auto/{$publicId}";
    }

    private function buildPublicId(string $sourceKey): string
    {
        $base = pathinfo($sourceKey, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_\-\/]/', '-', (string) $base) ?: 'video';

        return trim($base, '/');
    }

    private function buildSignature(array $params, string $apiSecret): string
    {
        ksort($params);

        $payload = [];
        foreach ($params as $key => $value) {
            $payload[] = $key.'='.$value;
        }

        return sha1(implode('&', $payload).$apiSecret);
    }

    private function guessMimeType(string $sourceKey): string
    {
        return match (strtolower(pathinfo($sourceKey, PATHINFO_EXTENSION))) {
            'mov', 'qt' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }

    private function transcodeForDelivery(string $inputPath): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'kulsah-transcoded-');

        if ($outputPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for video transcoding.');
        }

        $targetPath = $outputPath.'.mp4';
        @unlink($outputPath);

        $maxHeight = max(360, (int) config('video.transcode_max_height', 720));
        $preset = (string) config('video.transcode_preset', 'veryfast');
        $crf = (int) config('video.transcode_crf', 28);
        $audioBitrate = (string) config('video.transcode_audio_bitrate', '128k');

        $process = new Process([
            'ffmpeg',
            '-y',
            '-i',
            $inputPath,
            '-vf',
            'scale='.$maxHeight.':'.$maxHeight.':force_original_aspect_ratio=decrease',
            '-c:v',
            'libx264',
            '-preset',
            $preset,
            '-crf',
            (string) $crf,
            '-c:a',
            'aac',
            '-b:a',
            $audioBitrate,
            '-movflags',
            '+faststart',
            $targetPath,
        ]);

        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($targetPath);
            throw new RuntimeException('Video transcoding failed: '.$process->getErrorOutput());
        }

        return $targetPath;
    }

    private function postMultipart(string $url, array $fields): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Cloudinary upload request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(15, (int) config('video.cloudinary_upload_timeout_seconds', 120)),
            CURLOPT_TIMEOUT => (int) config('video.cloudinary_upload_timeout_seconds', 120),
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Cloudinary upload request failed: '.$error);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Cloudinary returned an invalid response.');
        }

        if ($status >= 400) {
            return $decoded + ['http_status' => $status];
        }

        return $decoded;
    }
}
