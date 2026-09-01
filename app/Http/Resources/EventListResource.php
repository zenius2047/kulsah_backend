<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EventListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;
        $creator = $event->relationLoaded('creator') ? $event->creator : null;
        $viewerId = (string) $request->user()?->id;
        $ticketTypes = $this->normalizeTicketTypes($event);
        $startingPrice = collect($ticketTypes)->min('price');
        $ticketsSold = (int) ($event->tickets_sold ?? 0);
        $capacity = (int) ($event->capacity ?? 0);
        $isOwner = (string) $event->user_id === $viewerId;

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'event_type' => $event->venue_type,
            'status' => $event->status,
            'cover_image_url' => $event->cover_image_url,
            'creator' => [
                'id' => $event->user_id,
                'name' => $creator?->name,
                'handle' => ltrim((string) ($creator?->username ?: $creator?->name ?: 'unknown'), '@'),
                'avatar_url' => $creator?->avatar,
                'is_verified' => (bool) ($creator?->verified ?? false),
            ],
            'starts_at' => optional($event->starts_at)?->toIso8601String(),
            'ends_at' => optional($event->ends_at)?->toIso8601String(),
            'timezone' => $event->timezone,
            'venue' => [
                'name' => $event->venue_name,
                'address' => $event->venue_address,
                'city' => data_get($event->venue_meta ?? [], 'city'),
                'country' => data_get($event->venue_meta ?? [], 'country'),
                'latitude' => data_get($event->venue_meta ?? [], 'latitude'),
                'longitude' => data_get($event->venue_meta ?? [], 'longitude'),
                'meeting_url' => $event->meeting_url,
            ],
            'pricing' => [
                'is_free' => (float) ($startingPrice ?? 0) <= 0,
                'starting_price' => (float) ($startingPrice ?? 0),
                'currency' => $event->currency,
            ],
            'capacity' => $capacity,
            'tickets_sold' => $ticketsSold,
            'tickets_remaining' => max(0, $capacity - $ticketsSold),
            'is_sold_out' => $capacity > 0 && $ticketsSold >= $capacity,
            'viewer' => [
                'is_owner' => $isOwner,
                'has_booked' => (bool) ($this->has_booked ?? false),
                'can_book' => $event->status === 'published' && ! $isOwner && $this->canBook($event),
                'can_edit' => $isOwner,
            ],
            'created_at' => optional($event->created_at)?->toIso8601String(),
            'updated_at' => optional($event->updated_at)?->toIso8601String(),
        ];
    }

    private function normalizeTicketTypes(Event $event): array
    {
        $ticketTypes = is_array($event->ticket_types) ? $event->ticket_types : [];

        return collect($ticketTypes)->map(function (array $ticketType, int $index) use ($event): array {
            $quantity = (int) ($ticketType['quantity'] ?? 0);
            $soldCount = (int) ($ticketType['sold_quantity'] ?? 0);
            $id = (int) ($ticketType['id'] ?? ($index + 1));

            return [
                'id' => $id,
                'name' => (string) ($ticketType['name'] ?? 'Ticket'),
                'description' => $ticketType['description'] ?? null,
                'price' => (float) ($ticketType['price'] ?? 0),
                'currency' => $event->currency,
                'quantity' => $quantity,
                'sold_count' => $soldCount,
                'remaining_count' => max(0, $quantity - $soldCount),
                'minimum_per_order' => (int) ($ticketType['minimum_per_order'] ?? 1),
                'maximum_per_order' => (int) ($ticketType['maximum_per_order'] ?? 10),
                'is_available' => max(0, $quantity - $soldCount) > 0,
            ];
        })->all();
    }

    private function canBook(Event $event): bool
    {
        return ($event->starts_at === null || $event->starts_at->isFuture()) && ((int) $event->capacity > (int) $event->tickets_sold);
    }
}
