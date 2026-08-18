<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengePrizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'rank_from' => $this->rank_from, 'rank_to' => $this->rank_to, 'reward_type' => $this->reward_type->value, 'title' => $this->title, 'description' => $this->description, 'currency' => $this->currency, 'amount' => $this->amount, 'quantity' => $this->quantity, 'metadata' => $this->metadata];
    }
}
