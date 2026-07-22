<?php

namespace App\Services;

use App\Models\CloudinaryWebhookEvent;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CloudinaryWebhookService
{
    public function __construct(
        private readonly CloudinaryService $cloudinaryService,
    ) {
    }

    /**
     * @return array{status: string, event: CloudinaryWebhookEvent, video?: Video|null}
     */
    public function process(array $payload, string $body, ?string $timestamp, ?string $signature): array
    {
        if (! $this->cloudinaryService->verifyNotificationSignature($body, $timestamp, $signature)) {
            throw new RuntimeException('Invalid Cloudinary webhook signature.');
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

        return [
            'status' => $isFailure ? 'failed' : 'processed',
            'event' => $event->fresh(),
            'video' => $video->fresh(),
        ];
    }

    private function resolveEventId(array $payload, string $body): string
    {
        foreach ([
            data_get($payload, 'notification_id'),
            data_get($payload, 'event_id'),
            data_get($payload, 'batch_id'),
            data_get($payload, 'request_id'),
            data_get($payload, 'asset_id'),
            data_get($payload, 'public_id'),
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
            data_get($payload, 'context.video_id'),
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
            $video = Video::query()
                ->where('cloudinary_public_id', $publicId)
                ->orWhere('cloudinary_render_id', $publicId)
                ->get()
                ->first(function (Video $candidate) use ($publicId): bool {
                    return data_get($candidate->metadata, 'cloudinary_render_public_id') === $publicId;
                });

            if ($video) {
                return $video;
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
