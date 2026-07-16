<?php

namespace App\Http\Resources;

use App\Models\VideoPlaylist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoPlaylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var VideoPlaylist $playlist */
        $playlist = $this->resource;

        return [
            'id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'name' => $playlist->name,
            'videos_count' => (int) ($playlist->videos_count ?? ($playlist->relationLoaded('videos') ? $playlist->videos->count() : 0)),
            'created_at' => optional($playlist->created_at)?->toIso8601String(),
            'updated_at' => optional($playlist->updated_at)?->toIso8601String(),
            'videos' => VideoResource::collection($this->whenLoaded('videos'))->resolve($request),
        ];
    }
}
