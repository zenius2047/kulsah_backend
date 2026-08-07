<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'idempotency_key' => $this->idempotency_key,
            'event_id' => $this->event_id,
            'buyer_id' => $this->buyer_id,
            'ticket_type_code' => $this->ticket_type_code,
            'ticket_type_name' => $this->ticket_type_name,
            'ticket_type_snapshot' => $this->ticket_type_snapshot ?? [],
            'quantity' => (int) $this->quantity,
            'unit_price' => $this->unit_price,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'metadata' => $this->metadata ?? [],
            'purchased_at' => optional($this->purchased_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'event' => $this->whenLoaded('event', fn () => new EventResource($this->event)),
            'buyer' => $this->whenLoaded('buyer', fn () => new UserResource($this->buyer)),
            'tickets' => $this->whenLoaded('tickets', fn () => EventTicketResource::collection($this->tickets), []),
        ];
    }
}
