<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;
        $creator = $event->relationLoaded('creator') ? $event->creator : null;
        $ticketTypes = $this->normalizeTicketTypes($event);
        $viewerBookings = $event->relationLoaded('viewerBookings') ? $event->viewerBookings : collect();
        $isOwner = (string) $event->user_id === (string) $request->user()?->id;
        $ticketsSold = (int) ($event->tickets_sold ?? 0);
        $capacity = (int) ($event->capacity ?? 0);
        $startingPrice = collect($ticketTypes)->min('price');

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
                'seating_map_enabled' => (bool) data_get($event->venue_meta ?? [], 'seating_map_enabled', false),
                'seating_map_url' => data_get($event->venue_meta ?? [], 'seating_map_url'),
            ],
            'ticket_types' => $this->normalizeDetailTicketTypes($event),
            'stats' => [
                'tickets_sold' => $ticketsSold,
                'tickets_remaining' => max(0, $capacity - $ticketsSold),
                'capacity' => $capacity,
            ],
            'viewer' => [
                'is_owner' => $isOwner,
                'has_booked' => $viewerBookings->isNotEmpty(),
                'can_book' => $event->status === 'published' && ! $isOwner && $this->canBook($event),
                'can_edit' => $isOwner,
                'bookings' => $viewerBookings->values()->map(fn ($booking) => [
                    'id' => $booking->id,
                    'reference' => $booking->reference,
                    'quantity' => (int) $booking->quantity,
                    'ticket_type_id' => data_get($booking->ticket_type_snapshot, 'id'),
                    'ticket_type_name' => $booking->ticket_type_name,
                    'status' => $booking->status,
                    'tickets' => $booking->relationLoaded('tickets')
                        ? $booking->tickets->values()->map(fn ($ticket) => [
                            'ticket_id' => $ticket->ticket_id,
                            'status' => $ticket->status,
                            'verification_url' => $ticket->verification_url,
                            'qr_code_url' => $ticket->qr_code_url,
                        ])->all()
                        : [],
                ])->all(),
            ],
            'capacity' => (int) $event->capacity,
            'currency' => $event->currency,
            'tickets_sold' => $ticketsSold,
            'tickets_remaining' => max(0, $capacity - $ticketsSold),
            'is_sold_out' => $capacity > 0 && $ticketsSold >= $capacity,
            'creator_insights' => $isOwner ? [
                'tickets_sold' => $ticketsSold,
                'capacity' => $capacity,
                'attendance_percentage' => $capacity > 0 ? (int) round(($ticketsSold / $capacity) * 100) : 0,
                'gross_revenue' => $this->grossRevenue($event),
                'currency' => $event->currency,
                'payout_status' => 'pending',
            ] : null,
            'created_at' => optional($event->created_at)?->toIso8601String(),
            'updated_at' => optional($event->updated_at)?->toIso8601String(),
        ];
    }

    private function normalizeDetailTicketTypes(Event $event): array
    {
        $ticketTypes = is_array($event->ticket_types) ? $event->ticket_types : [];

        return collect($ticketTypes)->map(function (array $ticketType, int $index) use ($event): array {
            $quantity = (int) ($ticketType['quantity'] ?? 0);
            $soldCount = (int) ($ticketType['sold_quantity'] ?? 0);
            $id = (int) ($ticketType['id'] ?? ($index + 1));

            return [
                'id' => $id,
                'code' => (string) ($ticketType['code'] ?? Str::slug((string) ($ticketType['name'] ?? 'ticket'))),
                'name' => (string) ($ticketType['name'] ?? 'Ticket'),
                'description' => $ticketType['description'] ?? null,
                'price' => (float) ($ticketType['price'] ?? 0),
                'currency' => $event->currency,
                'quantity' => $quantity,
                'sold_count' => $soldCount,
                'sold_quantity' => $soldCount,
                'remaining_count' => max(0, $quantity - $soldCount),
                'available_quantity' => max(0, $quantity - $soldCount),
                'minimum_per_order' => (int) ($ticketType['minimum_per_order'] ?? 1),
                'maximum_per_order' => (int) ($ticketType['maximum_per_order'] ?? 10),
                'is_available' => max(0, $quantity - $soldCount) > 0,
            ];
        })->all();
    }

    private function normalizeTicketTypes(Event $event): array
    {
        $ticketTypes = is_array($event->ticket_types) ? $event->ticket_types : [];

        return collect($ticketTypes)->map(function (array $ticketType): array {
            $quantity = (int) ($ticketType['quantity'] ?? 0);
            $soldCount = (int) ($ticketType['sold_quantity'] ?? 0);

            return [
                'id' => (int) ($ticketType['id'] ?? 0),
                'code' => (string) ($ticketType['code'] ?? Str::slug((string) ($ticketType['name'] ?? 'ticket'))),
                'name' => (string) ($ticketType['name'] ?? 'Ticket'),
                'description' => $ticketType['description'] ?? null,
                'price' => (float) ($ticketType['price'] ?? 0),
                'quantity' => $quantity,
                'sold_quantity' => $soldCount,
                'available_quantity' => max(0, $quantity - $soldCount),
            ];
        })->all();
    }

    private function canBook(Event $event): bool
    {
        return ($event->starts_at === null || $event->starts_at->isFuture()) && ((int) $event->capacity > (int) $event->tickets_sold);
    }

    private function grossRevenue(Event $event): float
    {
        $ticketTypes = is_array($event->ticket_types) ? $event->ticket_types : [];

        return (float) collect($ticketTypes)->sum(function (array $ticketType): float {
            return ((float) ($ticketType['price'] ?? 0)) * ((int) ($ticketType['sold_quantity'] ?? 0));
        });
    }
}
