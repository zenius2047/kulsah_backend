<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'sort_order' => $this->sort_order,
            'video' => VideoResource::make($this->whenLoaded('video')),
            'cover_url' => data_get($this->metadata, 'cover_url'),
            'cover_frame_time_ms' => data_get($this->metadata, 'cover_frame_time_ms'),
        ];
    }
}
