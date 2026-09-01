<?php

namespace App\Services;

use App\Jobs\VideoUploaded;
use App\Models\CloudinaryWebhookEvent;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CloudinaryWebhookService
{
    public function __construct(
        private readonly CloudinaryService $cloudinaryService,
    ) {}

    /**
     * @return array{status: string, event: CloudinaryWebhookEvent, video?: Video|null}
     */
    public function process(array $payload, string $body, ?string $timestamp, ?string $signature): array
    {
        if (! $this->cloudinaryService->verifyNotificationSignature($body, $timestamp, $signature)) {
            throw new RuntimeException('Invalid Cloudinary webhook signature.');
        }

        if (config('app.debug')) {
            $publicId = data_get($payload, 'public_id');
            $normalizedPublicId = is_string($publicId) ? $this->normalizeCloudinaryPublicId($publicId) : null;

            Log::info('Cloudinary webhook received.', [
                'keys' => array_values(array_intersect([
                    'notification_id',
                    'event_id',
                    'batch_id',
                    'request_id',
                    'asset_id',
                    'public_id',
                    'status',
                    'notification_type',
                    'event_type',
                    'type',
                    'video_id',
                    'context',
                    'asset',
                    'eager',
                    'error',
                ], array_keys($payload))),
                'video_id' => data_get($payload, 'video_id'),
                'context_video_id' => $this->resolveContextValue(data_get($payload, 'context'), 'video_id'),
                'asset_context_video_id' => $this->resolveContextValue(data_get($payload, 'asset.context'), 'video_id'),
                'batch_id' => data_get($payload, 'batch_id'),
                'request_id' => data_get($payload, 'request_id'),
                'public_id' => data_get($payload, 'public_id'),
                'normalized_public_id' => $normalizedPublicId,
                'render_public_id' => is_string($normalizedPublicId) ? $this->extractRenderPublicIdFromWebhookPublicId($normalizedPublicId) : null,
                'status' => data_get($payload, 'status'),
            ]);
        }

        $eventId = $this->resolveEventId($payload, $body);
        $eventType = $this->resolveEventType($payload);

        $event = CloudinaryWebhookEvent::query()->firstOrNew([
            'event_id' => $eventId,
        ]);

        if ($event->exists && $event->processed_at) {
            return [
                'status' => 'duplicate',
                'event' => $event,
                'video' => null,
            ];
        }

        $event->fill([
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
        $event->save();

        $video = $this->resolveVideo($payload);

        if (! $video) {
            if (config('app.debug')) {
                Log::warning('Cloudinary webhook could not resolve a video.', [
                    'video_id' => data_get($payload, 'video_id'),
                    'context_video_id' => $this->resolveContextValue(data_get($payload, 'context'), 'video_id'),
                    'asset_context_video_id' => $this->resolveContextValue(data_get($payload, 'asset.context'), 'video_id'),
                    'batch_id' => data_get($payload, 'batch_id'),
                    'request_id' => data_get($payload, 'request_id'),
                    'public_id' => data_get($payload, 'public_id'),
                    'normalized_public_id' => is_string(data_get($payload, 'public_id')) ? $this->normalizeCloudinaryPublicId((string) data_get($payload, 'public_id')) : null,
                    'status' => data_get($payload, 'status'),
                ]);
            }

            throw new RuntimeException('Unable to match Cloudinary webhook to a video record.');
        }

        $isFailure = $this->isFailurePayload($payload);

        DB::transaction(function () use ($event, $video, $payload, $isFailure): void {
            if ($isFailure) {
                $video->update($this->buildFailureUpdate($video, $payload));
            } else {
                $video->update($this->buildSuccessUpdate($video, $payload));
            }

            $event->forceFill([
                'processed_at' => now(),
            ])->save();
        });
        if (! $isFailure) {
            event(new VideoUploaded($video->fresh()));
        }

        return [
            'status' => $isFailure ? 'failed' : 'processed',
            'event' => $event->fresh(),
            'video' => $video->fresh(),
        ];
    }

    private function resolveEventId(array $payload, string $body): string
    {
        foreach ([
            data_get($payload, 'batch_id'),
            data_get($payload, 'request_id'),
            data_get($payload, 'asset_id'),
            data_get($payload, 'public_id'),
            data_get($payload, 'notification_id'),
            data_get($payload, 'event_id'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return sha1($body);
    }

    private function resolveEventType(array $payload): string
    {
        foreach ([
            data_get($payload, 'notification_type'),
            data_get($payload, 'event_type'),
            data_get($payload, 'type'),
            data_get($payload, 'status'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'cloudinary.webhook';
    }

    private function resolveVideo(array $payload): ?Video
    {
        foreach ([
            data_get($payload, 'video_id'),
            $this->resolveContextValue(data_get($payload, 'context'), 'video_id'),
            $this->resolveContextValue(data_get($payload, 'asset.context'), 'video_id'),
        ] as $candidate) {
            if (is_numeric($candidate)) {
                $video = Video::query()->find((int) $candidate);
                if ($video) {
                    return $video;
                }
            }
        }

        foreach ([
            data_get($payload, 'batch_id'),
            data_get($payload, 'request_id'),
        ] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $video = Video::query()
                ->where('cloudinary_render_id', $candidate)
                ->orWhere('cloudinary_asset_id', $candidate)
                ->first();

            if ($video) {
                return $video;
            }
        }

        $publicId = data_get($payload, 'public_id');
        if (is_string($publicId) && $publicId !== '') {
            $normalizedPublicId = $this->normalizeCloudinaryPublicId($publicId);
            $renderPublicId = $this->extractRenderPublicIdFromWebhookPublicId($normalizedPublicId);
            $renderHash = $this->extractRenderHashFromPublicId($normalizedPublicId);

            $metadataCandidates = array_values(array_unique(array_filter([
                $publicId,
                $normalizedPublicId,
                $renderPublicId,
            ], static fn ($value) => is_string($value) && $value !== '')));

            $video = Video::query()
                ->where(function ($query) use ($publicId, $metadataCandidates, $renderHash): void {
                    $query->where('cloudinary_public_id', $publicId)
                        ->orWhere('cloudinary_render_id', $publicId)
                        ->orWhere(function ($metadataQuery) use ($metadataCandidates): void {
                            foreach ($metadataCandidates as $candidate) {
                                $metadataQuery->orWhere('metadata->cloudinary_render_public_id', $candidate);
                            }
                        });

                    if (is_string($renderHash) && $renderHash !== '') {
                        $query->orWhere('metadata->cloudinary_render_hash', $renderHash);
                    }
                })
                ->first();

            if ($video) {
                return $video;
            }
        }

        return null;
    }

    private function normalizeCloudinaryPublicId(string $publicId): string
    {
        $publicId = trim($publicId, '/');
        $publicId = preg_replace('#^https?://[^/]+/video/upload/#', '', $publicId) ?: $publicId;
        $publicId = preg_replace('#^v\d+/#', '', $publicId) ?: $publicId;

        return trim($publicId, '/');
    }

    private function extractRenderHashFromPublicId(string $publicId): ?string
    {
        $publicId = trim($publicId, '/');

        if ($publicId === '') {
            return null;
        }

        $basename = basename($publicId);

        if (preg_match('/([a-f0-9]{40})$/i', $basename, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function extractRenderPublicIdFromWebhookPublicId(string $publicId): ?string
    {
        $publicId = trim($publicId, '/');

        if ($publicId === '') {
            return null;
        }

        $rendersPrefix = 'renders/';

        if (Str::contains($publicId, '/renders/')) {
            return Str::after($publicId, '/renders/');
        }

        if (Str::startsWith($publicId, $rendersPrefix)) {
            return Str::after($publicId, $rendersPrefix);
        }

        return $publicId;
    }

    private function resolveContextValue(array|string|null $context, string $key): ?string
    {
        if (is_array($context)) {
            $value = data_get($context, $key);

            return is_scalar($value) ? (string) $value : null;
        }

        if (! is_string($context) || $context === '') {
            return null;
        }

        foreach (explode('|', $context) as $pair) {
            [$pairKey, $pairValue] = array_pad(explode('=', $pair, 2), 2, null);

            if ($pairKey === $key && $pairValue !== null) {
                return $pairValue;
            }
        }

        return null;
    }

    private function isFailurePayload(array $payload): bool
    {
        $status = strtolower((string) (data_get($payload, 'status') ?? data_get($payload, 'notification_type') ?? ''));

        return in_array($status, ['failed', 'error', 'failure'], true)
            || data_get($payload, 'error') !== null
            || data_get($payload, 'eager_error') !== null;
    }

    private function buildSuccessUpdate(Video $video, array $payload): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $publicId = $this->resolveRenderPublicId($video, $payload);
        $renderedUrl = $this->resolveRenderedUrl($payload);
        $streamingUrl = $this->resolveStreamingUrl($payload, $publicId, $video);
        $posterUrl = $this->resolvePosterUrl($payload, $video);

        return array_filter([
            'status' => 'ready',
            'render_status' => 'ready',
            'processing_status' => 'ready',
            'processing_error' => null,
            'playback_type' => 'hls',
            'hls_url' => $streamingUrl,
            'fallback_mp4_url' => $renderedUrl,
            'processed_at' => now(),
            'failed_at' => null,
            'progress_percentage' => 100,
            'rendered_url' => $renderedUrl,
            'streaming_url' => $streamingUrl,
            'cdn_url' => $renderedUrl ?: $streamingUrl ?: $video->cdn_url,
            'poster_url' => $posterUrl,
            'cloudinary_asset_id' => data_get($payload, 'asset_id') ?: $video->cloudinary_asset_id,
            'cloudinary_render_id' => data_get($payload, 'batch_id') ?: data_get($payload, 'request_id') ?: $video->cloudinary_render_id,
            'render_completed_at' => now(),
            'metadata' => array_merge($metadata, [
                'cloudinary_webhook_event' => $payload,
                'cloudinary_webhook_event_type' => $this->resolveEventType($payload),
                'render_status' => 'ready',
                'progress_percentage' => 100,
                'render_completed_at' => now()->toISOString(),
                'cloudinary_render_public_id' => $publicId,
            ]),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildFailureUpdate(Video $video, array $payload): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];

        return array_filter([
            'status' => 'failed',
            'render_status' => 'failed',
            'processing_status' => 'processing_failed',
            'processing_error' => data_get($payload, 'error.message') ?: data_get($payload, 'error') ?: 'Cloudinary processing failed.',
            'failed_at' => now(),
            'metadata' => array_merge($metadata, [
                'cloudinary_webhook_event' => $payload,
                'cloudinary_webhook_event_type' => $this->resolveEventType($payload),
                'render_status' => 'failed',
                'render_error' => data_get($payload, 'error.message')
                    ?: data_get($payload, 'error')
                    ?: data_get($payload, 'eager_error')
                    ?: 'Cloudinary render failed.',
            ]),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function resolveRenderedUrl(array $payload): ?string
    {
        foreach ([
            data_get($payload, 'rendered_url'),
            data_get($payload, 'secure_url'),
            data_get($payload, 'eager.0.secure_url'),
            data_get($payload, 'derived.0.secure_url'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveStreamingUrl(array $payload, ?string $publicId, Video $video): ?string
    {
        foreach ([
            data_get($payload, 'streaming_url'),
            data_get($payload, 'eager.0.secure_url'),
            data_get($payload, 'derived.0.secure_url'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        if (is_string($publicId) && $publicId !== '') {
            return $this->cloudinaryService->generateStreamingUrlFromPublicId($publicId);
        }

        if (is_string($video->cloudinary_public_id) && $video->cloudinary_public_id !== '') {
            return $this->cloudinaryService->generateStreamingUrlFromPublicId($video->cloudinary_public_id);
        }

        return null;
    }

    private function resolvePosterUrl(array $payload, Video $video): ?string
    {
        foreach ([
            data_get($payload, 'poster_url'),
            data_get($payload, 'eager.1.secure_url'),
            data_get($payload, 'derived.1.secure_url'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $video->poster_url;
    }

    private function resolveRenderPublicId(Video $video, array $payload): ?string
    {
        foreach ([
            data_get($payload, 'public_id'),
            data_get($video->metadata, 'cloudinary_render_public_id'),
            $video->cloudinary_render_id,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
