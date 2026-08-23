<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorBattleSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'challenge_id' => $this->challenge_id,
            'challenge_winner_id' => $this->challenge_winner_id,
            'challenge_entry_id' => $this->challenge_entry_id,
            'recipient_user_id' => $this->recipient_user_id,
            'status' => $this->status,
            'vote_count' => (int) $this->vote_count,
            'vote_coin_amount' => (int) $this->vote_coin_amount,
            'conversion_rate' => (string) $this->conversion_rate,
            'usd_amount' => (string) $this->usd_amount,
            'wallet_transaction_id' => $this->wallet_transaction_id,
            'attempts' => (int) $this->attempts,
            'failure_reason' => $this->failure_reason,
            'metadata' => $this->metadata ?? [],
            'processed_at' => optional($this->processed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}