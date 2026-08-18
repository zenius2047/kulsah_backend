<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class CloudinaryService
{
    public function getCloudName(): string
    {
        return (string) config('services.cloudinary.cloud_name', '');
    }

    public function getApiKey(): string
    {
        return (string) config('services.cloudinary.api_key', '');
    }

    public function getApiSecret(): string
    {
        return (string) config('services.cloudinary.api_secret', '');
    }

    public function signParameters(array $params): string
    {
        ksort($params);

        $payload = [];
        foreach ($params as $key => $value) {
            $payload[] = $key.'='.(string) $value;
        }

        return sha1(implode('&', $payload).$this->getApiSecret());
    }

    public function verifyNotificationSignature(string $body, ?string $timestamp, ?string $signature): bool
    {
        if ($timestamp === null || $timestamp === '' || $signature === null || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        if ((int) $timestamp < strtotime('-2 hours')) {
            return false;
        }

        $payload = $body.$timestamp.$this->getApiSecret();
        $expectedSha1 = sha1($payload);
        $expectedSha256 = hash('sha256', $payload);

        return hash_equals($expectedSha1, $signature) || hash_equals($expectedSha256, $signature);
    }

    public function uploadVideoFromS3Key(string $sourceKey, ?string $sourceDisk = null): array
    {
        return $this->uploadMediaFromS3Key($sourceKey, 'video', $sourceDisk);
    }

    public function uploadImageFromS3Key(string $sourceKey, ?string $sourceDisk = null): array
    {
        return $this->uploadMediaFromS3Key($sourceKey, 'image', $sourceDisk);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadMediaFromS3Key(string $sourceKey, string $resourceType = 'video', ?string $sourceDisk = null): array
    {
        $disk = $sourceDisk ?: config('video.storage_disk', 's3');
        $cloudName = $this->getCloudName();
        $apiKey = $this->getApiKey();
        $apiSecret = $this->getApiSecret();
        $folder = trim((string) config('services.cloudinary.folder', 'kulsah/community'), '/');

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
            $sourceDiagnostics = $this->buildMediaDiagnostics($tempPath, 'source');

            if ($resourceType === 'video' && filter_var(config('video.transcode_enabled', true), FILTER_VALIDATE_BOOL)) {
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

            $uploadDiagnostics = $this->buildMediaDiagnostics($uploadPath, 'upload');

            $publicId = $this->buildPublicId($sourceKey);
            $timestamp = time();
            $params = $this->buildSignatureParams($folder, $publicId, $timestamp, $transcodeFailed);
            $signature = $this->signParameters($params);
            $uploadUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload";
            $mimeType = $this->guessMimeType($uploadPath);

            $postFields = [
                'file' => new \CURLFile($uploadPath, $mimeType, basename($sourceKey)),
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'public_id' => $publicId,
                'overwrite' => 'true',
                'unique_filename' => 'false',
                'use_filename' => 'false',
                'signature' => $signature,
                'resource_type' => $resourceType,
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

        $cloudinaryDiagnostics = array_filter([
            'http_status' => $response['status'] ?? null,
            'raw_body' => $response['body'] ?? null,
            'decoded_response' => $response['decoded'] ?? null,
            'curl_error' => $response['curl_error'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if (($response['status'] ?? 0) >= 400 || isset($response['decoded']['error'])) {
            Log::error('Cloudinary rejected video upload.', array_filter([
                'source_key' => $sourceKey,
                'folder' => $folder,
                'public_id' => $publicId,
                'transcode_failed' => $transcodeFailed,
                'source_diagnostics' => $sourceDiagnostics,
                'upload_diagnostics' => $uploadDiagnostics,
                'cloudinary' => $cloudinaryDiagnostics,
            ], static fn ($value) => $value !== null && $value !== ''));

            $message = is_array($response['decoded']['error'] ?? null)
                ? ($response['decoded']['error']['message'] ?? 'Cloudinary upload failed.')
                : (string) ($response['decoded']['error'] ?? 'Cloudinary upload failed.');

            throw new RuntimeException($message.' Cloudinary response: '.($response['body'] ?? ''));
        }

        if (! isset($response['decoded']['secure_url'], $response['decoded']['public_id'])) {
            Log::error('Cloudinary returned an invalid video response.', array_filter([
                'source_key' => $sourceKey,
                'folder' => $folder,
                'public_id' => $publicId,
                'source_diagnostics' => $sourceDiagnostics,
                'upload_diagnostics' => $uploadDiagnostics,
                'cloudinary' => $cloudinaryDiagnostics,
            ], static fn ($value) => $value !== null && $value !== ''));

            throw new RuntimeException('Cloudinary did not return a valid video response. Response body: '.($response['body'] ?? ''));
        }

        $publicId = $response['decoded']['public_id'];

        return [
            'cdn_url' => $resourceType === 'video'
                ? $this->generateStreamingUrlFromPublicId($publicId)
                : $this->generateImageUrlFromPublicId($publicId),
            'rendered_url' => $response['decoded']['secure_url'] ?? null,
            'stream_url' => $resourceType === 'video'
                ? $this->generateStreamingUrlFromPublicId($publicId)
                : $this->generateImageUrlFromPublicId($publicId),
            'streaming_url' => $resourceType === 'video'
                ? $this->generateStreamingUrlFromPublicId($publicId)
                : $this->generateImageUrlFromPublicId($publicId),
            'cloudinary_public_id' => $publicId,
            'cloudinary_asset_id' => $response['decoded']['asset_id'] ?? null,
            'thumbnail_url' => $resourceType === 'video'
                ? $this->generatePosterUrlFromPublicId($publicId)
                : $this->generateImageUrlFromPublicId($publicId),
            'poster_url' => $resourceType === 'video'
                ? $this->generatePosterUrlFromPublicId($publicId)
                : $this->generateImageUrlFromPublicId($publicId),
            'duration' => isset($response['decoded']['duration']) ? (int) round((float) $response['decoded']['duration']) : null,
            'streaming_profile' => config('video.cloudinary_stream_max_resolution', '2160p'),
            'metadata' => array_merge($response['decoded'], [
                'upload_diagnostics' => $uploadDiagnostics,
                'source_diagnostics' => $sourceDiagnostics,
            ]),
        ];
    }

    public function uploadVideoFromLocalPath(string $localPath, string $originalName = 'edited.mp4'): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('Unable to upload a missing rendered video file to Cloudinary.');
        }

        $cloudName = $this->getCloudName();
        $apiKey = $this->getApiKey();
        $apiSecret = $this->getApiSecret();
        $folder = trim((string) config('services.cloudinary.folder', 'kulsah/videos'), '/').'/renders';

        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            throw new RuntimeException('Cloudinary credentials are not configured.');
        }

        $publicId = $this->buildRenderedPublicId($originalName);
        $timestamp = time();
        $params = [
            'folder' => $folder,
            'public_id' => $publicId,
            'overwrite' => 'true',
            'unique_filename' => 'false',
            'use_filename' => 'false',
            'timestamp' => $timestamp,
        ];

        $signature = $this->signParameters($params);
        $mimeType = $this->guessMimeType($localPath);

        $response = $this->postMultipart(
            "https://api.cloudinary.com/v1_1/{$cloudName}/video/upload",
            [
                'file' => new \CURLFile($localPath, $mimeType, basename($originalName)),
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'public_id' => $publicId,
                'overwrite' => 'true',
                'unique_filename' => 'false',
                'use_filename' => 'false',
                'signature' => $signature,
                'resource_type' => 'video',
            ],
            $apiKey,
            $apiSecret
        );

        if (($response['status'] ?? 0) >= 400 || isset($response['decoded']['error'])) {
            $message = is_array($response['decoded']['error'] ?? null)
                ? (string) ($response['decoded']['error']['message'] ?? 'Cloudinary upload failed.')
                : (string) ($response['decoded']['error'] ?? 'Cloudinary upload failed.');

            throw new RuntimeException($message.' Cloudinary response: '.($response['body'] ?? ''));
        }

        if (! isset($response['decoded']['secure_url'], $response['decoded']['public_id'])) {
            throw new RuntimeException('Cloudinary did not return a valid rendered video response. Response body: '.($response['body'] ?? ''));
        }

        return [
            'render_status' => 'ready',
            'status' => 'ready',
            'cdn_url' => $this->generateStreamingUrlFromPublicId($response['decoded']['public_id']),
            'rendered_url' => $response['decoded']['secure_url'],
            'stream_url' => $this->generateStreamingUrlFromPublicId($response['decoded']['public_id']),
            'streaming_url' => $this->generateStreamingUrlFromPublicId($response['decoded']['public_id']),
            'cloudinary_public_id' => $response['decoded']['public_id'],
            'cloudinary_asset_id' => $response['decoded']['asset_id'] ?? null,
            'thumbnail_url' => $this->generatePosterUrlFromPublicId($response['decoded']['public_id']),
            'poster_url' => $this->generatePosterUrlFromPublicId($response['decoded']['public_id']),
            'duration' => isset($response['decoded']['duration']) ? (int) round((float) $response['decoded']['duration']) : null,
            'streaming_profile' => config('video.cloudinary_stream_max_resolution', '2160p'),
            'metadata' => array_merge($response['decoded'], [
                'upload_source' => 'ffmpeg',
                'upload_path' => $localPath,
            ]),
        ];
    }

    public function generateAdaptiveStreamUrl(string $publicId): string
    {
        return $this->generateStreamingUrlFromPublicId($publicId);
    }

    public function generateStreamingUrlFromPublicId(string $publicId): string
    {
        $cloudName = $this->getCloudName();
        $manifestExtension = ltrim((string) config('video.cloudinary_stream_manifest_extension', 'm3u8'), '.');
        $maxResolution = (string) config('video.cloudinary_stream_max_resolution', '2160p');
        $deliveryProfile = 'sp_auto:maxres_'.$maxResolution;

        return "https://res.cloudinary.com/{$cloudName}/video/upload/{$deliveryProfile}/{$publicId}.{$manifestExtension}";
    }

    public function generateDerivedVideoUrl(string $publicId): string
    {
        return 'https://res.cloudinary.com/'.$this->getCloudName()."/video/upload/a_auto,f_auto,q_auto/{$publicId}";
    }

    public function generateThumbnailUrl(string $publicId): string
    {
        return $this->generatePosterUrlFromPublicId($publicId);
    }

    public function generateImageUrlFromPublicId(string $publicId): string
    {
        return "https://res.cloudinary.com/{$this->getCloudName()}/image/upload/{$publicId}";
    }

    public function generatePosterUrlFromPublicId(string $publicId): string
    {
        $cloudName = $this->getCloudName();

        return "https://res.cloudinary.com/{$cloudName}/video/upload/so_0,w_720,h_1280,c_fill,f_jpg,q_auto/{$publicId}";
    }

    public function generatePosterAtTimeUrl(string $publicId, int $frameTimeMs): string
    {
        $seconds = number_format(max(0, $frameTimeMs) / 1000, 3, '.', '');

        return 'https://res.cloudinary.com/'.$this->getCloudName().'/video/upload/so_'.$seconds.',w_720,h_1280,c_fill,f_jpg,q_auto/'.$publicId;
    }

    private function buildPublicId(string $sourceKey): string
    {
        $base = pathinfo($sourceKey, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_\-\/]/', '-', (string) $base) ?: 'video';

        return trim($base, '/');
    }

    private function buildRenderedPublicId(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_\-\/]/', '-', (string) $base) ?: 'edited-video';

        return trim('renders/'.Str::uuid()->toString().'-'.$base, '/');
    }

    private function buildSignatureParams(string $folder, string $publicId, int $timestamp, bool $transcodeFailed): array
    {
        $params = [
            'folder' => $folder,
            'public_id' => $publicId,
            'overwrite' => 'true',
            'unique_filename' => 'false',
            'use_filename' => 'false',
            'timestamp' => $timestamp,
        ];

        if ($transcodeFailed) {
            $params['context'] = 'transcode_fallback=true';
        }

        return $params;
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

        // Keep the mezzanine high enough for Cloudinary to generate lower renditions later.
        $maxHeight = max(2160, (int) config('video.transcode_max_height', 2160));
        $preset = (string) config('video.transcode_preset', 'veryfast');
        $crf = (int) config('video.transcode_crf', 28);
        $audioBitrate = (string) config('video.transcode_audio_bitrate', '128k');

        $process = new Process([
            'ffmpeg',
            '-y',
            '-i',
            $inputPath,
            '-vf',
            'scale=-2:'.$maxHeight,
            '-c:v',
            'libx264',
            '-pix_fmt',
            'yuv420p',
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

        return [
            'status' => $status,
            'body' => $body,
            'decoded' => is_array($decoded) ? $decoded : [],
            'curl_error' => $error !== '' ? $error : null,
        ];
    }

    private function buildMediaDiagnostics(string $path, string $label): array
    {
        $diagnostics = [
            'label' => $label,
            'path' => $path,
            'exists' => is_file($path),
            'size_bytes' => is_file($path) ? filesize($path) : null,
            'mime_type' => function_exists('mime_content_type') && is_file($path) ? @mime_content_type($path) : null,
        ];

        if (! is_file($path)) {
            return $diagnostics;
        }

        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_format',
            '-show_streams',
            '-print_format',
            'json',
            $path,
        ]);

        $process->setTimeout(20);
        $process->run();

        $diagnostics['ffprobe_success'] = $process->isSuccessful();
        $diagnostics['ffprobe_exit_code'] = $process->getExitCode();

        if ($process->isSuccessful()) {
            $decoded = json_decode($process->getOutput(), true);

            if (is_array($decoded)) {
                $diagnostics['format'] = array_filter([
                    'format_name' => data_get($decoded, 'format.format_name'),
                    'format_long_name' => data_get($decoded, 'format.format_long_name'),
                    'duration' => data_get($decoded, 'format.duration'),
                    'bit_rate' => data_get($decoded, 'format.bit_rate'),
                    'size' => data_get($decoded, 'format.size'),
                ], static fn ($value) => $value !== null && $value !== '');

                $diagnostics['streams'] = collect(data_get($decoded, 'streams', []))
                    ->map(static function (array $stream): array {
                        return array_filter([
                            'index' => $stream['index'] ?? null,
                            'codec_type' => $stream['codec_type'] ?? null,
                            'codec_name' => $stream['codec_name'] ?? null,
                            'codec_long_name' => $stream['codec_long_name'] ?? null,
                            'profile' => $stream['profile'] ?? null,
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'pix_fmt' => $stream['pix_fmt'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'channels' => $stream['channels'] ?? null,
                            'channel_layout' => $stream['channel_layout'] ?? null,
                            'duration' => $stream['duration'] ?? null,
                            'bit_rate' => $stream['bit_rate'] ?? null,
                        ], static fn ($value) => $value !== null && $value !== '');
                    })
                    ->values()
                    ->all();
            }
        } else {
            $diagnostics['ffprobe_error'] = trim($process->getErrorOutput());
        }

        return $diagnostics;
    }
}
