<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CloudinaryVideoRendererService
{
    public function __construct(
        private readonly CloudinaryService $cloudinaryService,
        private readonly CloudinaryTransformationBuilder $transformationBuilder,
        private readonly VideoStorageService $videoStorageService,
    ) {
    }

    public function startRender(Video $video, array $timeline): array
    {
        $video = $video->fresh();

        if (! $video) {
            throw new RuntimeException('The selected video no longer exists.');
        }

        if (! $video->source_key) {
            throw new RuntimeException('The video is missing a source asset.');
        }

        $sourceDisk = data_get($video->metadata, 'storage_disk', config('video.storage_disk', 's3'));
        $sourceUrl = $this->videoStorageService->resolveAccessibleUrl($sourceDisk, (string) $video->source_key);
        $renderPlan = $this->transformationBuilder->buildRenderTransformations(array_merge($timeline, [
            'video_id' => $video->id,
        ]));
        $notificationUrl = rtrim((string) config('services.cloudinary.webhook_url', url('/api/v1/cloudinary/webhook')), '/');

        Log::info('Cloudinary render requested.', [
            'video_id' => $video->id,
            'source_key' => $video->source_key,
            'source_url' => $sourceUrl,
            'render_hash' => $renderPlan['render_hash'],
        ]);

        return $this->requestAsyncRender(
            video: $video,
            sourceUrl: $sourceUrl,
            renderPlan: $renderPlan,
            notificationUrl: $notificationUrl,
        );
    }

    public function generateStreamingUrl(string $publicId): string
    {
        return $this->cloudinaryService->generateStreamingUrlFromPublicId($publicId);
    }

    public function generatePosterUrl(string $publicId): string
    {
        return $this->cloudinaryService->generatePosterUrlFromPublicId($publicId);
    }

    private function requestAsyncRender(Video $video, string $sourceUrl, array $renderPlan, string $notificationUrl): array
    {
        $cloudName = $this->cloudinaryService->getCloudName();
        $apiKey = $this->cloudinaryService->getApiKey();
        $apiSecret = $this->cloudinaryService->getApiSecret();

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Cloudinary credentials are not configured.');
        }

        $renderFolder = trim((string) config('services.cloudinary.folder', 'kulsah/videos'), '/').'/renders';
        $renderPublicId = $this->buildRenderPublicId($video, $renderPlan['render_hash']);
        $timestamp = time();

        $video->forceFill([
            'render_status' => 'processing',
            'metadata' => array_merge(is_array($video->metadata) ? $video->metadata : [], [
                'cloudinary_render_public_id' => $renderPublicId,
                'cloudinary_render_folder' => $renderFolder,
                'cloudinary_render_hash' => $renderPlan['render_hash'],
                'cloudinary_render_timeline' => $renderPlan['timeline'],
            ]),
        ])->save();

        $params = [
            'timestamp' => $timestamp,
            'type' => 'upload',
            'resource_type' => 'video',
            'folder' => $renderFolder,
            'public_id' => $renderPublicId,
            'file' => $sourceUrl,
            'context' => 'video_id='.(string) $video->id.'|render_hash='.$renderPlan['render_hash'],
            'eager' => $renderPlan['video_transformation'].'|'.$renderPlan['poster_transformation'],
            'eager_async' => 'true',
            'eager_notification_url' => $notificationUrl,
            'overwrite' => 'true',
            'unique_filename' => 'false',
            'use_filename' => 'false',
        ];

        $response = $this->postMultipart(
            "https://api.cloudinary.com/v1_1/{$cloudName}/video/upload",
            $params,
            $apiKey,
            $apiSecret
        );

        if (($response['status'] ?? 0) >= 400 || isset($response['decoded']['error'])) {
            $message = is_array($response['decoded']['error'] ?? null)
                ? (string) ($response['decoded']['error']['message'] ?? 'Cloudinary render request failed.')
                : (string) ($response['decoded']['error'] ?? 'Cloudinary render request failed.');

            throw new RuntimeException($message);
        }

        $cloudinaryAssetId = (string) ($response['decoded']['asset_id'] ?? $response['decoded']['public_id'] ?? '');
        $cloudinaryRenderId = (string) ($response['decoded']['batch_id'] ?? $response['decoded']['request_id'] ?? $response['decoded']['public_id'] ?? '');

        return [
            'render_status' => 'processing',
            'cloudinary_asset_id' => $cloudinaryAssetId !== '' ? $cloudinaryAssetId : null,
            'cloudinary_render_id' => $cloudinaryRenderId !== '' ? $cloudinaryRenderId : null,
            'rendered_url' => null,
            'streaming_url' => $this->cloudinaryService->generateStreamingUrlFromPublicId($renderPublicId),
            'poster_url' => $this->cloudinaryService->generatePosterUrlFromPublicId($renderPublicId),
            'metadata' => [
                'cloudinary_render_folder' => $renderFolder,
                'cloudinary_render_public_id' => $renderPublicId,
                'cloudinary_render_request' => $response['decoded'] ?? [],
                'cloudinary_render_hash' => $renderPlan['render_hash'],
                'cloudinary_render_source_url' => $sourceUrl,
                'cloudinary_render_timeline' => $renderPlan['timeline'],
                'cloudinary_render_transformation' => $renderPlan['video_transformation'],
                'cloudinary_render_poster_transformation' => $renderPlan['poster_transformation'],
                'cloudinary_render_batch_id' => $response['decoded']['batch_id'] ?? null,
            ],
        ];
    }

    private function buildRenderPublicId(Video $video, string $renderHash): string
    {
        $sourceBase = pathinfo((string) $video->source_key, PATHINFO_FILENAME);
        $sourceBase = preg_replace('/[^A-Za-z0-9_\-\/]/', '-', $sourceBase) ?: 'video';

        return trim("{$video->user_id}/{$sourceBase}-{$renderHash}", '/');
    }

    private function postMultipart(string $url, array $fields, ?string $apiKey = null, ?string $apiSecret = null): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Cloudinary render request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(15, (int) config('video.cloudinary_upload_timeout_seconds', 120)),
            CURLOPT_TIMEOUT => (int) config('video.cloudinary_upload_timeout_seconds', 120),
        ]);

        if ($apiKey !== null && $apiKey !== '' && $apiSecret !== null && $apiSecret !== '') {
            curl_setopt_array($ch, [
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $apiKey.':'.$apiSecret,
            ]);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Cloudinary render request failed: '.$error);
        }

        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'body' => $body,
            'decoded' => is_array($decoded) ? $decoded : [],
            'curl_error' => $error !== '' ? $error : null,
        ];
    }
}
