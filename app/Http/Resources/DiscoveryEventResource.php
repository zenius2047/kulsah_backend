<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscoveryEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;
        $creator = $event->relationLoaded('creator') ? $event->creator : null;
        $ticketTypes = collect(is_array($event->ticket_types) ? $event->ticket_types : []);
        $minimumTicketPrice = $ticketTypes->min(fn (array $ticket): float => (float) ($ticket['price'] ?? 0));
        $hasAvailableTicketType = $ticketTypes->contains(function (array $ticket): bool {
            $quantity = (int) ($ticket['quantity'] ?? 0);
            $sold = (int) ($ticket['sold_quantity'] ?? 0);

            return $quantity > $sold;
        });
        $hasCapacity = (int) $event->capacity <= 0 || (int) $event->tickets_sold < (int) $event->capacity;

        return [
            'id' => (int) $event->id,
            'title' => $event->title,
            'creator' => [
                'id' => (int) $event->user_id,
                'name' => $creator?->name ?: $creator?->username,
                'handle' => ltrim((string) $creator?->username, '@'),
                'avatar_url' => $creator?->avatar,
            ],
            'starts_at' => optional($event->starts_at)?->toIso8601String(),
            'ends_at' => optional($event->ends_at)?->toIso8601String(),
            'venue' => $event->venue_name ?: $event->venue_address,
            'location_type' => $event->venue_type,
            'cover_url' => $event->cover_image_url,
            'duration_minutes' => $event->starts_at && $event->ends_at
                ? max(0, (int) $event->starts_at->diffInMinutes($event->ends_at))
                : null,
            'tickets_available' => $hasCapacity && ($ticketTypes->isEmpty() || $hasAvailableTicketType),
            'minimum_ticket_price' => $minimumTicketPrice !== null ? (float) $minimumTicketPrice : null,
            'currency' => $event->currency,
        ];
    }
}
