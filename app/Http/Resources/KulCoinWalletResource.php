<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KulCoinWalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'account_key' => $this->account_key,
            'account_name' => $this->account_name,
            'currency_code' => $this->currency_code,
            'available_kc' => (int) $this->available_balance_kc,
            'bonus_kc' => (int) $this->bonus_balance_kc,
            'total_kc' => (int) $this->available_balance_kc + (int) $this->bonus_balance_kc,
            'status' => $this->status,
            'last_ledger_at' => optional($this->last_ledger_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
