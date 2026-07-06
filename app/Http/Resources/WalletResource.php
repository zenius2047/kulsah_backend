<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'account_key' => $this->account_key,
            'account_name' => $this->account_name,
            'base_currency' => $this->base_currency,
            'status' => $this->status,
            'balances' => [
                'available_usd' => $this->available_balance_usd,
                'pending_usd' => $this->pending_balance_usd,
                'held_usd' => $this->held_balance_usd,
                'total_usd' => round(((float) $this->available_balance_usd) + ((float) $this->pending_balance_usd) + ((float) $this->held_balance_usd), 4),
            ],
            'last_ledger_at' => $this->last_ledger_at,
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
