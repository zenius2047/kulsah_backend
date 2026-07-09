<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KulCoinGiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'coin_cost' => (int) $this->coin_cost,
            'is_active' => (bool) $this->is_active,
            'icon_url' => $this->icon_url,
            'animation_url' => $this->animation_url,
            'metadata' => $this->metadata ?? [],
        ];
    }
}
