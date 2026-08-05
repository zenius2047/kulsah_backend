<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KulCoinLedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kulcoin_transaction_id' => $this->kulcoin_transaction_id,
            'kulcoin_wallet_id' => $this->kulcoin_wallet_id,
            'entry_type' => $this->entry_type,
            'balance_bucket' => $this->balance_bucket,
            'amount_kc' => (int) $this->amount_kc,
            'running_balance_kc' => (int) $this->running_balance_kc,
            'narration' => $this->narration,
            'metadata' => $this->metadata ?? [],
            'settlement_available_at' => optional($this->settlement_available_at)?->toIso8601String(),
            'settled_at' => optional($this->settled_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
