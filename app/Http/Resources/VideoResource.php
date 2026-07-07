<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'caption' => $this->caption,
            'visibility' => $this->visibility,
            'is_premium' => (bool) $this->is_premium,
            'cdn_url' => $this->cdn_url,
            'thumbnail' => $this->thumbnail_url,
            'duration' => $this->duration,
            'status' => $this->status,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
