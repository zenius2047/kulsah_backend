<?php

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Video $video */
        $video = $this->resource;
        $metadata = is_array($video->metadata) ? $video->metadata : [];

        $category = $video->content_type
            ?: (is_array($video->content_types) ? ($video->content_types[0] ?? null) : null)
            ?: data_get($metadata, 'category')
            ?: 'General';

        return [
            'id' => (string) $video->id,
            'title' => (string) ($video->title ?: $video->caption ?: 'Untitled'),
            'views' => $this->formatCount($video->views_count ?? data_get($metadata, 'views', data_get($metadata, 'views_count', 0))),
            'date' => optional($video->created_at)?->format('Y-m-d') ?? '',
            'duration' => $this->formatDuration($video->duration ?? data_get($metadata, 'duration_seconds')),
            'category' => (string) $category,
            'img' => (string) ($video->poster_url ?: $video->thumbnail_url ?: data_get($metadata, 'background', data_get($metadata, 'thumbnail', ''))),
            'likes' => $this->formatCount($video->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
            'premium' => $video->visibility === 'premium',
            'draft' => $video->status !== 'ready',
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

    private function formatDuration(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '00:00';
        }

        $seconds = max(0, (int) round((float) $value));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
            : sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }
}
