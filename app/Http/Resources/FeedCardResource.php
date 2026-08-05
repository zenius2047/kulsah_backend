<?php

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Video $video */
        $video = $this->resource;
        $creator = $video->relationLoaded('user') ? $video->user : null;
        $metadata = is_array($video->metadata) ? $video->metadata : [];

        $creatorName = $creator?->name ?: $creator?->username ?: 'Unknown Creator';
        $creatorHandle = $creator?->username ? ltrim((string) $creator->username, '@') : null;

        return [
            'id' => (string) $video->id,
            'creator' => $creatorName,
            'creatorId' => (string) ($creator?->id ?: data_get($metadata, 'creator_id')),
            'handle' => $creatorHandle,
            'avatar' => $creator?->avatar,
            'banner' => $creator?->banner,
            'caption' => (string) ($video->caption ?: $video->title ?: ''),
            'contentType' => $video->content_type,
            'contentTypes' => is_array($video->content_types) ? $video->content_types : [],
            'hashtags' => data_get($metadata, 'caption_hashtags', []),
            'mentions' => data_get($metadata, 'caption_mentions', []),
            'background' => $video->thumbnail_url ?: data_get($metadata, 'background'),
            'video' => $video->playback_url,
            'likes' => $this->formatCount($video->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
            'comments' => $this->formatCount($video->comments_count ?? data_get($metadata, 'comments', data_get($metadata, 'comments_count', 0))),
            'views' => $this->formatCount($video->views_count ?? data_get($metadata, 'views', data_get($metadata, 'views_count', 0))),
            'isLiked' => (bool) ($video->is_liked ?? data_get($metadata, 'is_liked', false)),
            'isSubscribed' => (bool) ($video->is_subscribed ?? data_get($metadata, 'is_subscribed', false)),
            'isPremium' => $video->visibility === 'premium' || (bool) data_get($metadata, 'is_premium', false),
            'ticketsAvailable' => (bool) data_get($metadata, 'tickets_available', false),
            'ticketLocation' => data_get($metadata, 'ticket_location'),
            'originalSound' => (bool) data_get($metadata, 'original_sound', true),
            'soundArtist' => data_get($metadata, 'sound_artist'),
            'soundTitle' => data_get($metadata, 'sound_title'),
            'following' => (bool) ($video->is_following ?? data_get($metadata, 'following', false)),
            'bookmarks' => $this->formatCount($video->bookmarks_count ?? data_get($metadata, 'bookmarks', data_get($metadata, 'bookmarks_count', 0))),
            'saves' => $this->formatCount($video->bookmarks_count ?? data_get($metadata, 'saves', data_get($metadata, 'saves_count', 0))),
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
