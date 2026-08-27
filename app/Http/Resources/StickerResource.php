<?php

namespace App\Http\Resources;

use App\Models\Sticker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StickerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Sticker $sticker */
        $sticker = $this->resource;
        return [
            'id' => $sticker->id,
            'name' => $sticker->name,
            'type' => $sticker->type,
            'media_url' => $sticker->media_url,
            'thumbnail_url' => $sticker->thumbnail_url ?: $sticker->media_url,
            'width' => $sticker->width,
            'height' => $sticker->height,
            'is_animated' => (bool) $sticker->is_animated,
            'duration_ms' => $sticker->duration_ms,
            'visibility' => $sticker->visibility,
            'pack' => $sticker->relationLoaded('pack') && $sticker->pack ? [
                'id' => $sticker->pack->id, 'name' => $sticker->pack->name, 'slug' => $sticker->pack->slug,
            ] : null,
            'tags' => $sticker->tags ?: [],
            'usage_count' => (int) $sticker->usage_count,
            'favorite_count' => (int) $sticker->favorite_count,
        ];
    }
}
