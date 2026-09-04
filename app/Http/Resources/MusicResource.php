<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MusicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $artwork = is_array($this->resource['artwork'] ?? null) ? $this->resource['artwork'] : [];

        return [
            'id' => (string) ($this->resource['id'] ?? ''),
            'provider' => (string) ($this->resource['provider'] ?? 'audius'),
            'external_id' => $this->resource['external_id'] ?? null,
            'title' => $this->resource['title'] ?? null,
            'artist' => $this->resource['artist'] ?? null,
            'artist_id' => $this->resource['artist_id'] ?? null,
            'artist_username' => $this->resource['artist_username'] ?? null,
            'artist_verified' => (bool) ($this->resource['artist_verified'] ?? false),
            'artwork' => $artwork,
            'thumbnail_artwork' => $this->resource['thumbnail_artwork'] ?? data_get($artwork, 'thumbnail'),
            'large_artwork' => $this->resource['large_artwork'] ?? data_get($artwork, 'large'),
            'duration' => $this->resource['duration'] ?? null,
            'genre' => $this->resource['genre'] ?? null,
            'mood' => $this->resource['mood'] ?? null,
            'tags' => $this->resource['tags'] ?? [],
            'release_date' => $this->resource['release_date'] ?? null,
            'play_count' => (int) ($this->resource['play_count'] ?? 0),
            'favorite_count' => (int) ($this->resource['favorite_count'] ?? 0),
            'repost_count' => (int) ($this->resource['repost_count'] ?? 0),
            'streamable' => (bool) ($this->resource['streamable'] ?? false),
            'downloadable' => (bool) ($this->resource['downloadable'] ?? false),
            'permalink' => $this->resource['permalink'] ?? null,
            'stream_url' => $this->resource['stream_url'] ?? null,
            'stream_endpoint' => $this->resource['stream_endpoint'] ?? null,
            'source_attribution' => $this->resource['source_attribution'] ?? [],
            'is_saved' => (bool) ($this->resource['is_saved'] ?? false),
            'usage_count' => (int) ($this->resource['usage_count'] ?? 0),
            'license' => $this->resource['license'] ?? null,
            'rights_status' => $this->resource['rights_status'] ?? null,
        ];
    }
}
