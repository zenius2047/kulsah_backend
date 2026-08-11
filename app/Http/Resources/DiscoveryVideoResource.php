<?php

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscoveryVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Video $video */
        $video = $this->resource;
        $creator = $video->relationLoaded('user') ? $video->user : null;
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $category = $video->content_type
            ?: (is_array($video->content_types) ? ($video->content_types[0] ?? null) : null)
            ?: data_get($metadata, 'category');

        return [
            'id' => (int) $video->id,
            'title' => $video->title,
            'caption' => $video->caption,
            'creator' => [
                'id' => (int) $video->user_id,
                'name' => $creator?->name ?: $creator?->username,
                'handle' => ltrim((string) $creator?->username, '@'),
                'avatar_url' => $creator?->avatar,
            ],
            'thumbnail_url' => $video->poster_url ?: $video->thumbnail_url,
            'playback_url' => $video->playback_url,
            'content_type' => 'video',
            'category' => $category,
            'duration_seconds' => $video->duration !== null ? (int) $video->duration : null,
            'stats' => [
                'views_count' => (int) ($video->views_count ?? 0),
                'likes_count' => (int) ($video->likes_count ?? 0),
                'comments_count' => (int) ($video->comments_count ?? 0),
            ],
            'viewer' => [
                'is_liked' => (bool) ($video->viewer_is_liked ?? false),
                'is_bookmarked' => (bool) ($video->viewer_is_bookmarked ?? false),
                'is_following_creator' => (bool) ($video->viewer_is_following_creator ?? false),
            ],
        ];
    }
}
