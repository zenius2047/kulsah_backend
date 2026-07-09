<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KulCoinPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'coin_amount' => (int) $this->coin_amount,
            'bonus_coin_amount' => (int) $this->bonus_coin_amount,
            'usd_price' => (string) $this->usd_price,
            'currency_code' => $this->currency_code,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'metadata' => $this->metadata ?? [],
        ];
    }
}
