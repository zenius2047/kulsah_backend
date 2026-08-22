<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $processingStatus = $this->processing_status?->value ?? ($this->status === 'ready' ? 'ready' : 'initialized');
        $uploadStatus = $this->upload_status?->value ?? data_get($metadata, 'upload_state', 'initialized');
        $isReady = $processingStatus === 'ready';
        $playbackUrl = $isReady ? $this->playback_url : null;
        $publicMetadata = Arr::except($metadata, [
            'storage_disk', 'thumbnail_disk', 'thumbnail_source_key', 'previous_source_key',
            'source_key', 'upload_diagnostics', 'source_diagnostics', 'error',
        ]);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'caption' => $this->caption,
            'visibility' => $this->visibility,
            'content_type' => $this->content_type,
            'content_types' => is_array($this->content_types) ? $this->content_types : [],
            'purpose' => $this->purpose?->value ?? 'post_video',
            'allowDuet' => (bool) ($this->allow_duet ?? false),
            'isDuet' => (bool) ($this->duet_source_video_id ?? data_get($metadata, 'duet_source_video_id')),
            'duetSourceVideoId' => $this->duet_source_video_id ? (int) $this->duet_source_video_id : data_get($metadata, 'duet_source_video_id'),
            'playlist_ids' => $this->relationLoaded('playlists')
                ? $this->playlists->pluck('id')->sort()->values()->all()
                : [],
            'playlists_count' => $this->relationLoaded('playlists')
                ? $this->playlists->count()
                : null,
            'is_premium' => (bool) $this->is_premium,
            'cdn_url' => $isReady ? $this->cdn_url : null,
            'rendered_url' => $isReady ? $this->rendered_url : null,
            'stream_url' => $playbackUrl,
            'streaming_url' => $playbackUrl,
            'playback' => [
                'type' => $isReady ? ($this->playback_type ?: 'hls') : null,
                'url' => $playbackUrl,
                'fallbackUrl' => $isReady ? $this->fallback_mp4_url : null,
                'posterUrl' => $isReady ? ($this->poster_url ?: $this->thumbnail_url) : null,
            ],
            'thumbnail' => $this->poster_url ?: $this->thumbnail_url,
            'poster_url' => $this->poster_url ?: data_get($metadata, 'poster_url'),
            'duration' => $this->duration,
            'durationMs' => $this->duration_ms ?? ($this->duration ? $this->duration * 1000 : null),
            'width' => $this->width,
            'height' => $this->height,
            'aspectRatio' => $this->aspect_ratio,
            'fps' => $this->fps,
            'status' => $this->status,
            'upload_status' => $uploadStatus,
            'processing_status' => $processingStatus,
            'render_status' => $this->render_status,
            'progress_percentage' => (int) ($this->progress_percentage ?? 0),
            'requires_editing' => (bool) data_get($metadata, 'requires_editing', false),
            'upload_state' => $uploadStatus,
            'views_count' => (int) ($this->views_count ?? 0),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'render_completed_at' => optional($this->render_completed_at)?->toIso8601String(),
            'hashtags' => data_get($metadata, 'caption_hashtags', []),
            'mentions' => data_get($metadata, 'caption_mentions', []),
            'metadata' => $publicMetadata,
        ];
    }
}