<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletLedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_transaction_id' => $this->wallet_transaction_id,
            'wallet_id' => $this->wallet_id,
            'entry_type' => $this->entry_type,
            'balance_bucket' => $this->balance_bucket,
            'currency' => $this->wallet?->base_currency ?? config('wallet.base_currency', 'GHS'),
            'amount' => $this->amount_usd,
            'amount_usd' => $this->amount_usd,
            'running_balance' => $this->running_balance_usd,
            'running_balance_usd' => $this->running_balance_usd,
            'narration' => $this->narration,
            'metadata' => $this->metadata,
            'wallet' => $this->whenLoaded('wallet', fn () => new WalletResource($this->wallet)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
