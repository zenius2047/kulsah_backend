<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'counterparty_wallet_id' => $this->counterparty_wallet_id,
            'currency' => $this->wallet?->base_currency ?? config('wallet.base_currency', 'GHS'),
            'local_currency' => $this->local_currency,
            'local_amount' => $this->local_amount,
            'amount' => $this->usd_amount,
            'usd_amount' => $this->usd_amount,
            'fx_rate_used' => $this->fx_rate_used,
            'platform_fee_usd' => $this->platform_fee_usd,
            'processor_fee_usd' => $this->processor_fee_usd,
            'net_amount' => $this->net_usd_amount,
            'net_usd_amount' => $this->net_usd_amount,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'processed_at' => $this->processed_at,
            'performed_by_user_id' => $this->performed_by_user_id,
            'wallet' => $this->whenLoaded('wallet', fn () => new WalletResource($this->wallet)),
            'counterparty_wallet' => $this->whenLoaded('counterpartyWallet', fn () => new WalletResource($this->counterpartyWallet)),
            'entries' => $this->whenLoaded('entries', fn () => WalletLedgerEntryResource::collection($this->entries), []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
