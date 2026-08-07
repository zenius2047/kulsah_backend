<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'ticket_number' => (int) $this->ticket_number,
            'event_id' => $this->event_id,
            'purchase_id' => $this->event_ticket_purchase_id,
            'buyer_id' => $this->buyer_id,
            'status' => $this->status,
            'verification_url' => $this->verification_url,
            'qr_code_url' => $this->qr_code_url,
            'scan_signature' => $this->scan_signature,
            'metadata' => $this->metadata ?? [],
            'verified_at' => optional($this->verified_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'event' => $this->whenLoaded('event', fn () => new EventResource($this->event)),
            'buyer' => $this->whenLoaded('buyer', fn () => new UserResource($this->buyer)),
        ];
    }
}
