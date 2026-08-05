<?php

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorVideoDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Video $video */
        $video = $this->resource;
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $creator = $video->relationLoaded('user') ? $video->user : null;

        return [
            'id' => (string) $video->id,
            'creator' => $creator?->name ?: $creator?->username ?: 'Unknown Creator',
            'creator_id' => (string) $video->user_id,
            'handle' => '@'.ltrim((string) ($creator?->username ?: $creator?->name ?: 'unknown'), '@'),
            'avatar' => $creator?->avatar,
            'banner' => $creator?->banner,
            'caption' => (string) ($video->caption ?: $video->title ?: ''),
            'background' => $video->poster_url ?: $video->thumbnail_url ?: data_get($metadata, 'background'),
            'video' => $video->playback_url,
            'views' => $this->formatCount($video->views_count ?? data_get($metadata, 'views', data_get($metadata, 'views_count', 0))),
            'likes' => $this->formatCount($video->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
            'comments_count' => $this->formatCount($video->comments_count ?? data_get($metadata, 'comments_count', data_get($metadata, 'comments', 0))),
            'comments' => $this->whenLoaded('comments', fn () => VideoCommentResource::collection($this->comments)->resolve($request), []),
            'otherVideos' => $this->whenLoaded('otherVideos', fn () => CreatorVideoDetailResource::collection($this->otherVideos)->resolve($request), []),
        ];
    }

    private function formatCount(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return '0';
        }

        $value = (float) $value;

        if ($value >= 1000000000) {
            return rtrim(rtrim(number_format($value / 1000000000, 1), '0'), '.').'B';
        }

        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.').'M';
        }

        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'K';
        }

        return (string) (int) $value;
    }
}
