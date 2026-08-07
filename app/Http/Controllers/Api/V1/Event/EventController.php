<?php

namespace App\Http\Controllers\Api\V1\Event;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventListResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventTicketPurchaseResource;
use App\Http\Resources\EventTicketResource;
use App\Models\Event as PlatformEvent;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use App\Services\EventMediaService;
use App\Services\EventTicketService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventController extends Controller
{
    public function __construct(
        private readonly EventMediaService $eventMediaService,
        private readonly EventTicketService $eventTicketService,
    )
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['upcoming', 'past', 'ongoing', 'all'])],
        ]);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = strtolower((string) ($validated['status'] ?? 'upcoming'));
        $viewerId = (int) $request->user()->id;
        $bookedEventIds = [];

        if ($page > 0) {
            $bookedEventIds = EventTicketPurchase::query()
                ->where('buyer_id', $viewerId)
                ->distinct()
                ->pluck('event_id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        $events = PlatformEvent::query()
            ->with(['creator.roles:id,name'])
            ->withCount('purchases')
            ->where('status', 'published')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%')
                        ->orWhere('venue_name', 'like', '%'.$search.'%')
                        ->orWhereHas('creator', function ($creatorQuery) use ($search): void {
                            $creatorQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('username', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($status === 'upcoming', function ($query): void {
                $query->where(function ($timeQuery): void {
                    $timeQuery->whereNull('starts_at')
                        ->orWhere('starts_at', '>=', now());
                });
            })
            ->when($status === 'past', function ($query): void {
                $query->whereNotNull('ends_at')->where('ends_at', '<', now());
            })
            ->when($status === 'ongoing', function ($query): void {
                $query->where('starts_at', '<=', now())
                    ->where(function ($timeQuery): void {
                        $timeQuery->whereNull('ends_at')
                            ->orWhere('ends_at', '>=', now());
                    });
            })
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $events->getCollection()->each(function (PlatformEvent $event) use ($viewerId, $bookedEventIds): void {
            $event->setAttribute('has_booked', in_array((int) $event->id, $bookedEventIds, true));
            $event->setAttribute('viewer_is_owner', (int) $event->user_id === $viewerId);
        });

        return response()->json([
            'data' => EventListResource::collection($events->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    public function creatorIndex(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $events = PlatformEvent::query()
            ->with(['creator.roles:id,name'])
            ->withCount('purchases')
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => EventListResource::collection($events->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateEventPayload($request);
        $coverImage = $request->file('cover_image');
        $uploadedCover = null;

        try {
            if ($coverImage instanceof UploadedFile) {
                $uploadedCover = $this->eventMediaService->storeCoverImage($coverImage, $request->user());
            }

            $event = DB::transaction(function () use ($request, $validated, $uploadedCover): PlatformEvent {
                return PlatformEvent::query()->create([
                    'user_id' => $request->user()->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'category' => $validated['category'],
                    'venue_type' => $validated['venue_type'],
                    'venue_name' => $validated['venue_name'] ?? null,
                    'venue_address' => $validated['venue_address'] ?? null,
                    'meeting_url' => $validated['meeting_url'] ?? null,
                    'starts_at' => $validated['starts_at'],
                    'ends_at' => $validated['ends_at'],
                    'timezone' => $validated['timezone'],
                    'capacity' => (int) $validated['capacity'],
                    'currency' => strtoupper((string) $validated['currency']),
                    'cover_image_disk' => $uploadedCover['disk'] ?? null,
                    'cover_image_key' => $uploadedCover['source_key'] ?? null,
                    'cover_image_url' => $uploadedCover['cover_image_url'] ?? null,
                    'ticket_types' => $this->normalizeTicketTypes($validated['ticket_types']),
                    'status' => $validated['status'] ?? 'draft',
                    'tickets_sold' => 0,
                ])->load(['creator.roles:id,name']);
            });
        } catch (Throwable $throwable) {
            if ($uploadedCover) {
                Storage::disk((string) $uploadedCover['disk'])->delete((string) $uploadedCover['source_key']);
            }

            throw $throwable;
        }

        $event->loadCount('purchases');

        return response()->json([
            'message' => 'Event created successfully.',
            'data' => new EventResource($event),
        ], 201);
    }

    public function creatorShow(Request $request, PlatformEvent $event)
    {
        $this->authorizeEventAccess($request, $event, allowUnpublished: true);

        $event->load(['creator.roles:id,name', 'purchases.buyer.roles:id,name', 'purchases.tickets']);
        $event->setRelation('viewerBookings', collect());
        $event->loadCount('purchases');

        return response()->json([
            'data' => new EventResource($event),
        ]);
    }

    public function show(Request $request, PlatformEvent $event)
    {
        $this->authorizeEventAccess($request, $event, allowUnpublished: false);

        $viewerBookings = EventTicketPurchase::query()
            ->where('event_id', $event->id)
            ->where('buyer_id', $request->user()->id)
            ->with(['tickets'])
            ->latest('id')
            ->get();

        $event->load(['creator.roles:id,name']);
        $event->setRelation('viewerBookings', $viewerBookings);
        $event->loadCount('purchases');

        return response()->json([
            'data' => new EventResource($event),
        ]);
    }

    public function update(Request $request, PlatformEvent $event)
    {
        $this->authorizeEventAccess($request, $event, allowUnpublished: true);

        $validated = $this->validateEventPayload($request, isUpdate: true, existingEvent: $event);
        $coverImage = $request->file('cover_image');
        $uploadedCover = null;

        try {
            if ($coverImage instanceof UploadedFile) {
                $uploadedCover = $this->eventMediaService->storeCoverImage($coverImage, $request->user());
            }

            DB::transaction(function () use ($event, $validated, $uploadedCover): void {
                $event->fill([
                    'title' => $validated['title'] ?? $event->title,
                    'description' => array_key_exists('description', $validated) ? $validated['description'] : $event->description,
                    'category' => $validated['category'] ?? $event->category,
                    'venue_type' => $validated['venue_type'] ?? $event->venue_type,
                    'venue_name' => array_key_exists('venue_name', $validated) ? $validated['venue_name'] : $event->venue_name,
                    'venue_address' => array_key_exists('venue_address', $validated) ? $validated['venue_address'] : $event->venue_address,
                    'meeting_url' => array_key_exists('meeting_url', $validated) ? $validated['meeting_url'] : $event->meeting_url,
                    'starts_at' => $validated['starts_at'] ?? $event->starts_at,
                    'ends_at' => $validated['ends_at'] ?? $event->ends_at,
                    'timezone' => $validated['timezone'] ?? $event->timezone,
                    'capacity' => array_key_exists('capacity', $validated) ? (int) $validated['capacity'] : $event->capacity,
                    'currency' => array_key_exists('currency', $validated) ? strtoupper((string) $validated['currency']) : $event->currency,
                    'status' => $validated['status'] ?? $event->status,
                ]);

                if ($uploadedCover !== null) {
                    $event->forceFill([
                        'cover_image_disk' => $uploadedCover['disk'],
                        'cover_image_key' => $uploadedCover['source_key'],
                        'cover_image_url' => $uploadedCover['cover_image_url'],
                    ]);
                }

                if (array_key_exists('ticket_types', $validated)) {
                    $event->ticket_types = $this->normalizeTicketTypes($validated['ticket_types'], $event);
                }

                $event->save();
            });
        } catch (Throwable $throwable) {
            if ($uploadedCover) {
                Storage::disk((string) $uploadedCover['disk'])->delete((string) $uploadedCover['source_key']);
            }

            throw $throwable;
        }

        $event->load(['creator.roles:id,name', 'purchases.buyer.roles:id,name']);
        $event->loadCount('purchases');

        return response()->json([
            'message' => 'Event updated successfully.',
            'data' => new EventResource($event),
        ]);
    }

    public function purchaseTicket(Request $request, PlatformEvent $event)
    {
        abort_unless($event->status === 'published', 403, 'This event is not available for ticket purchases.');

        $validated = $request->validate([
            'ticket_type_code' => ['nullable', 'string', 'max:80'],
            'ticket_type_name' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (($validated['ticket_type_code'] ?? null) === null && ($validated['ticket_type_name'] ?? null) === null) {
            throw ValidationException::withMessages([
                'ticket_type_code' => 'A ticket type code or name is required.',
            ]);
        }

        try {
            $purchase = DB::transaction(function () use ($request, $event, $validated): EventTicketPurchase {
                $lockedEvent = PlatformEvent::query()->lockForUpdate()->findOrFail($event->id);

                abort_unless($lockedEvent->status === 'published', 403, 'This event is not available for ticket purchases.');
                abort_unless($lockedEvent->starts_at === null || $lockedEvent->starts_at->isFuture(), 422, 'Ticket sales for this event have already closed.');
                abort_unless((int) $lockedEvent->tickets_sold < (int) $lockedEvent->capacity, 422, 'This event is sold out.');

                if (($validated['idempotency_key'] ?? null) !== null) {
                    $existing = EventTicketPurchase::query()
                        ->where('buyer_id', $request->user()->id)
                        ->where('event_id', $lockedEvent->id)
                        ->where('idempotency_key', $validated['idempotency_key'])
                        ->first();

                    if ($existing) {
                        return $existing->load(['event.creator.roles:id,name', 'buyer.roles:id,name', 'tickets']);
                    }
                }

                $ticketTypes = $this->normalizeTicketTypes($lockedEvent->ticket_types ?? []);
                $ticketType = $this->resolveTicketType($ticketTypes, $validated['ticket_type_code'] ?? null, $validated['ticket_type_name'] ?? null);
                $quantity = (int) $validated['quantity'];
                $availableQuantity = (int) ($ticketType['quantity'] ?? 0) - (int) ($ticketType['sold_quantity'] ?? 0);

                if ($availableQuantity < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Not enough tickets are available for the selected ticket type.',
                    ]);
                }

                $ticketType['sold_quantity'] = (int) ($ticketType['sold_quantity'] ?? 0) + $quantity;
                $lockedEvent->ticket_types = $ticketTypes->map(function (array $type) use ($ticketType): array {
                    return ($type['code'] ?? null) === $ticketType['code'] ? $ticketType : $type;
                })->values()->all();
                $lockedEvent->tickets_sold = (int) $lockedEvent->tickets_sold + $quantity;
                $lockedEvent->save();

                $purchase = EventTicketPurchase::query()->create([
                    'event_id' => $lockedEvent->id,
                    'buyer_id' => $request->user()->id,
                    'ticket_type_code' => $ticketType['code'],
                    'ticket_type_name' => $ticketType['name'],
                    'ticket_type_snapshot' => $ticketType,
                    'quantity' => $quantity,
                    'unit_price' => $ticketType['price'],
                    'total_amount' => $ticketType['price'] * $quantity,
                    'currency' => $lockedEvent->currency,
                    'status' => 'completed',
                    'reference' => (string) Str::uuid(),
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                    'metadata' => $validated['metadata'] ?? [],
                    'purchased_at' => now(),
                ]);

                $this->eventTicketService->issueTickets($purchase, $lockedEvent, $quantity);

                return $purchase->load([
                    'event.creator.roles:id,name',
                    'buyer.roles:id,name',
                    'tickets.event.creator.roles:id,name',
                    'tickets.buyer.roles:id,name',
                ]);
            });
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to purchase event tickets.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to purchase event tickets.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        $event->refresh()->load(['creator.roles:id,name', 'purchases.buyer.roles:id,name']);
        $event->loadCount('purchases');

        return response()->json([
            'message' => 'Event ticket purchased successfully.',
            'data' => [
                'event' => new EventResource($event),
                'purchase' => new EventTicketPurchaseResource($purchase),
            ],
        ], 201);
    }

    public function verifyTicket(Request $request)
    {
        abort_unless(
            $request->user()->roles()->whereIn('name', ['admin', 'creator'])->exists(),
            403,
            'You are not allowed to verify tickets.'
        );

        $validated = $request->validate([
            'ticket_id' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:255'],
        ]);

        $ticket = EventTicket::query()
            ->with(['event.creator.roles:id,name', 'buyer.roles:id,name', 'purchase'])
            ->where('ticket_id', $validated['ticket_id'])
            ->first();

        abort_unless($ticket, 404, 'Ticket not found.');

        $purchase = $ticket->purchase;
        abort_unless($purchase, 404, 'Ticket purchase not found.');

        abort_unless(
            $request->user()->roles()->where('name', 'admin')->exists() ||
            (string) $ticket->event->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to verify this ticket.'
        );

        $expectedSignature = $this->eventTicketService->buildScanSignature($purchase, $ticket->ticket_id);
        abort_unless(hash_equals($expectedSignature, (string) $validated['signature']), 422, 'Ticket signature is invalid.');

        if ($ticket->status !== 'used') {
            $ticket->forceFill([
                'status' => 'used',
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
            ])->save();
        }

        return response()->json([
            'message' => 'Ticket verified successfully.',
            'data' => new EventTicketResource($ticket->fresh(['event.creator.roles:id,name', 'buyer.roles:id,name'])),
        ]);
    }

    private function validateEventPayload(Request $request, bool $isUpdate = false, ?PlatformEvent $existingEvent = null): array
    {
        $validated = $request->validate([
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => [$isUpdate ? 'sometimes' : 'required', 'string', 'min:1', 'max:120'],
            'venue_type' => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(['physical', 'virtual'])],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:2000'],
            'starts_at' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'ends_at' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'timezone' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'capacity' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1', 'max:1000000'],
            'currency' => [$isUpdate ? 'sometimes' : 'required', 'string', 'size:3'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'cancelled'])],
            'cover_image' => ['sometimes', 'nullable', 'file', 'image', 'max:10240'],
            'ticket_types' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'ticket_types.*.code' => ['nullable', 'string', 'max:80'],
            'ticket_types.*.name' => ['required_with:ticket_types', 'string', 'min:1', 'max:120'],
            'ticket_types.*.description' => ['nullable', 'string', 'max:500'],
            'ticket_types.*.price' => ['required_with:ticket_types', 'numeric', 'min:0'],
            'ticket_types.*.quantity' => ['required_with:ticket_types', 'integer', 'min:1'],
        ]);

        $effectiveVenueType = $validated['venue_type'] ?? $existingEvent?->venue_type;
        $effectiveVenueName = array_key_exists('venue_name', $validated) ? $validated['venue_name'] : $existingEvent?->venue_name;
        $effectiveVenueAddress = array_key_exists('venue_address', $validated) ? $validated['venue_address'] : $existingEvent?->venue_address;
        $effectiveMeetingUrl = array_key_exists('meeting_url', $validated) ? $validated['meeting_url'] : $existingEvent?->meeting_url;
        $effectiveStartsAt = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $existingEvent?->starts_at;
        $effectiveEndsAt = array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $existingEvent?->ends_at;

        if ($effectiveVenueType === 'physical') {
            if (trim((string) $effectiveVenueName) === '') {
                throw ValidationException::withMessages([
                    'venue_name' => 'The venue name field is required for physical events.',
                ]);
            }

            if (trim((string) $effectiveVenueAddress) === '') {
                throw ValidationException::withMessages([
                    'venue_address' => 'The venue address field is required for physical events.',
                ]);
            }
        }

        if ($effectiveVenueType === 'virtual' && trim((string) $effectiveMeetingUrl) === '') {
            throw ValidationException::withMessages([
                'meeting_url' => 'The meeting URL field is required for virtual events.',
            ]);
        }

        if ($effectiveStartsAt && $effectiveEndsAt && strtotime((string) $effectiveEndsAt) <= strtotime((string) $effectiveStartsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end time must be after the start time.',
            ]);
        }

        if (array_key_exists('ticket_types', $validated)) {
            $ticketTypes = $this->normalizeTicketTypes($validated['ticket_types'], $existingEvent);
            $totalCapacity = array_sum(array_map(static fn (array $ticketType): int => (int) ($ticketType['quantity'] ?? 0), $ticketTypes));

            if (array_key_exists('capacity', $validated)) {
                if ($validated['capacity'] < $totalCapacity) {
                    throw ValidationException::withMessages([
                        'capacity' => 'The event capacity cannot be smaller than the total ticket inventory.',
                    ]);
                }

                if ($existingEvent && (int) $validated['capacity'] < (int) $existingEvent->tickets_sold) {
                    throw ValidationException::withMessages([
                        'capacity' => 'The event capacity cannot be smaller than the tickets already sold.',
                    ]);
                }
            } elseif ($existingEvent && (int) $existingEvent->capacity < $totalCapacity) {
                throw ValidationException::withMessages([
                    'capacity' => 'The event capacity cannot be smaller than the total ticket inventory.',
                ]);
            }

            $validated['ticket_types'] = $ticketTypes;
        }

        return $validated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ticketTypes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTicketTypes(array $ticketTypes, ?PlatformEvent $existingEvent = null): array
    {
        $existingTicketTypes = collect(is_array($existingEvent?->ticket_types) ? $existingEvent->ticket_types : [])
            ->keyBy(fn (array $ticketType): string => (string) ($ticketType['code'] ?? Str::slug((string) ($ticketType['name'] ?? 'ticket'))));

        $normalized = [];
        $seenCodes = [];

        foreach ($ticketTypes as $index => $ticketType) {
            if (! is_array($ticketType)) {
                throw ValidationException::withMessages([
                    "ticket_types.{$index}" => 'Each ticket type must be an object.',
                ]);
            }

            $name = trim((string) ($ticketType['name'] ?? ''));
            $code = trim((string) ($ticketType['code'] ?? ''));
            $code = $code !== '' ? Str::slug($code) : Str::slug($name);

            if ($code === '') {
                $code = 'ticket-'.$index;
            }

            if (in_array($code, $seenCodes, true)) {
                throw ValidationException::withMessages([
                    'ticket_types' => 'Each ticket type code must be unique.',
                ]);
            }

            $existingTicketType = $existingTicketTypes->get($code);
            $soldQuantity = (int) ($existingTicketType['sold_quantity'] ?? 0);
            $quantity = (int) ($ticketType['quantity'] ?? 0);

            if ($existingTicketType && $quantity < $soldQuantity) {
                throw ValidationException::withMessages([
                    'ticket_types' => 'The new quantity for a ticket type cannot be lower than the tickets already sold.',
                ]);
            }

            $normalized[] = [
                'code' => $code,
                'name' => $name,
                'description' => array_key_exists('description', $ticketType) ? $ticketType['description'] : null,
                'price' => (float) ($ticketType['price'] ?? 0),
                'quantity' => $quantity,
                'sold_quantity' => $soldQuantity,
            ];

            $seenCodes[] = $code;
        }

        if ($existingEvent) {
            foreach (is_array($existingEvent->ticket_types) ? $existingEvent->ticket_types : [] as $existingTicketType) {
                $existingCode = trim((string) ($existingTicketType['code'] ?? ''));
                $existingCode = $existingCode !== '' ? Str::slug($existingCode) : Str::slug((string) ($existingTicketType['name'] ?? 'ticket'));

                if ($existingCode === '' || in_array($existingCode, $seenCodes, true)) {
                    continue;
                }

                if ((int) ($existingTicketType['sold_quantity'] ?? 0) > 0) {
                    $normalized[] = [
                        'code' => $existingCode,
                        'name' => (string) ($existingTicketType['name'] ?? 'Ticket'),
                        'description' => $existingTicketType['description'] ?? null,
                        'price' => (float) ($existingTicketType['price'] ?? 0),
                        'quantity' => (int) ($existingTicketType['quantity'] ?? 0),
                        'sold_quantity' => (int) ($existingTicketType['sold_quantity'] ?? 0),
                    ];
                }
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ticketTypes
     * @return array<string, mixed>
     */
    private function resolveTicketType(array $ticketTypes, ?string $code, ?string $name): array
    {
        $normalizedCode = $code !== null ? Str::slug($code) : null;
        $normalizedName = $name !== null ? Str::lower(trim($name)) : null;

        foreach ($ticketTypes as $ticketType) {
            $ticketCode = Str::slug((string) ($ticketType['code'] ?? ''));
            $ticketName = Str::lower(trim((string) ($ticketType['name'] ?? '')));

            if ($normalizedCode !== null && $ticketCode === $normalizedCode) {
                return $ticketType;
            }

            if ($normalizedName !== null && $ticketName === $normalizedName) {
                return $ticketType;
            }
        }

        throw ValidationException::withMessages([
            'ticket_type_code' => 'The selected ticket type was not found for this event.',
        ]);
    }

    private function authorizeEventAccess(Request $request, PlatformEvent $event, bool $allowUnpublished): void
    {
        $isOwner = (string) $event->user_id === (string) $request->user()->id;

        if ($isOwner) {
            return;
        }

        if ($allowUnpublished) {
            abort(403, 'This event belongs to another creator.');
        }

        abort_unless($event->status === 'published', 403, 'This event is not available.');
    }
}
