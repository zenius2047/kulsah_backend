<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KulCoinTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'idempotency_key' => $this->idempotency_key,
            'type' => $this->type,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'counterparty_wallet_id' => $this->counterparty_wallet_id,
            'package_id' => $this->package_id,
            'gift_id' => $this->gift_id,
            'local_currency' => $this->local_currency,
            'local_amount' => $this->local_amount,
            'usd_amount' => $this->usd_amount,
            'coin_amount' => (int) $this->coin_amount,
            'bonus_coin_amount' => (int) $this->bonus_coin_amount,
            'net_coin_amount' => (int) $this->net_coin_amount,
            'description' => $this->description,
            'metadata' => $this->metadata ?? [],
            'processed_at' => optional($this->processed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'wallet' => $this->whenLoaded('wallet', fn () => new KulCoinWalletResource($this->wallet)),
            'counterparty_wallet' => $this->whenLoaded('counterpartyWallet', fn () => new KulCoinWalletResource($this->counterpartyWallet)),
            'package' => $this->whenLoaded('package', fn () => new KulCoinPackageResource($this->package)),
            'gift' => $this->whenLoaded('gift', fn () => new KulCoinGiftResource($this->gift)),
            'entries' => KulCoinLedgerEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
