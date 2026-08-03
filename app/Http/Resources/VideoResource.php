<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'caption' => $this->caption,
            'visibility' => $this->visibility,
            'content_type' => $this->content_type,
            'content_types' => is_array($this->content_types) ? $this->content_types : [],
            'playlist_ids' => $this->relationLoaded('playlists')
                ? $this->playlists->pluck('id')->sort()->values()->all()
                : [],
            'playlists_count' => $this->relationLoaded('playlists')
                ? $this->playlists->count()
                : null,
            'is_premium' => (bool) $this->is_premium,
            'cdn_url' => $this->cdn_url,
            'rendered_url' => $this->rendered_url,
            'stream_url' => $this->playback_url ?: data_get($metadata, 'stream_url'),
            'streaming_url' => $this->playback_url ?: data_get($metadata, 'streaming_url') ?: data_get($metadata, 'stream_url'),
            'thumbnail' => $this->poster_url ?: $this->thumbnail_url,
            'poster_url' => $this->poster_url ?: data_get($metadata, 'poster_url'),
            'duration' => $this->duration,
            'status' => $this->status,
            'render_status' => $this->render_status,
            'progress_percentage' => (int) ($this->progress_percentage ?? 0),
            'views_count' => (int) ($this->views_count ?? 0),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'render_completed_at' => optional($this->render_completed_at)?->toIso8601String(),
            'hashtags' => data_get($metadata, 'caption_hashtags', []),
            'mentions' => data_get($metadata, 'caption_mentions', []),
            'metadata' => $metadata,
        ];
    }
}
