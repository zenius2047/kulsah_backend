<?php

namespace App\Http\Controllers\Api\V1\Kulscan;

use App\Http\Controllers\Controller;
use App\Models\Event as PlatformEvent;
use App\Models\EventTicketPurchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatorEventsController extends Controller
{
    public function show(Request $request, PlatformEvent $event)
    {
        abort_unless((string) $event->user_id === (string) $request->user()->id, 403, 'You are not allowed to view this event.');

        $event->load([
            'purchases.buyer:id,name,username',
            'tickets.buyer:id,name,username',
        ]);

        $date = $event->starts_at instanceof Carbon ? $event->starts_at : null;
        $status = $this->resolveScreenStatus($event);
        $palette = $this->eventPalette($status);
        $ticketsSold = (int) ($event->tickets_sold ?? 0);
        $capacity = max(0, (int) $event->capacity);
        $checkedInCount = (int) $event->tickets->where('status', 'used')->count();
        $revenueTotal = round((float) $event->purchases->sum('total_amount'), 2);

        return response()->json([
            'message' => 'Event details loaded successfully.',
            'data' => [
                'id' => 'e-'.$event->id,
                'title' => (string) $event->title,
                'subtitle' => $this->buildSubtitle($event),
                'date_label' => $date?->format('D, M d') ?? 'TBA',
                'time_label' => $date?->format('g:i A') ?? 'TBA',
                'location' => $this->resolveEventLocation($event),
                'status' => $status,
                'category' => $this->resolveEventCategory($event->category),
                'summary' => $this->buildSummary($event),
                'banner_color' => $palette['color'],
                'banner_soft_color' => $palette['soft_color'],
                'accent_color' => $this->accentColor($palette['color']),
                'banner_image_url' => $event->cover_image_url ?: $this->fallbackBannerUrl($event),
                'stats' => [
                    [
                        'label' => 'Checked in',
                        'value' => (string) $checkedInCount,
                        'icon' => 'people-outline',
                    ],
                    [
                        'label' => 'Capacity',
                        'value' => $capacity > 0 ? (int) round(($ticketsSold / $capacity) * 100).'%' : '0%',
                        'icon' => 'stats-chart-outline',
                    ],
                    [
                        'label' => 'Revenue',
                        'value' => '$'.number_format($revenueTotal / 1000, 1).'k',
                        'icon' => 'cash-outline',
                    ],
                ],
                'schedule' => $this->buildSchedule($event),
                'attendees' => $this->buildAttendees($event),
            ],
        ], 200);
    }

    public function index(Request $request)
    {
        $events = PlatformEvent::query()
            ->with(['purchases.buyer:id,name,username'])
            ->where('user_id', $request->user()->id)
            ->orderByRaw('CASE WHEN starts_at >= ? THEN 0 WHEN starts_at <= ? AND ends_at >= ? THEN 1 ELSE 2 END', [now(), now(), now()])
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->get();

        $upcomingCount = $events->filter(fn (PlatformEvent $event): bool => $this->isUpcomingEvent($event))->count();
        $liveNowCount = $events->filter(fn (PlatformEvent $event): bool => $this->isLiveNowEvent($event))->count();
        $totalCapacity = $events->sum(fn (PlatformEvent $event): int => max(0, (int) $event->capacity));
        $totalSold = $events->sum(fn (PlatformEvent $event): int => max(0, (int) $event->tickets_sold));
        $capacityRate = $totalCapacity > 0 ? (int) round(($totalSold / $totalCapacity) * 100) : 0;

        return response()->json([
            'message' => 'Events loaded successfully.',
            'data' => [
                'overview' => [
                    [
                        'label' => 'Upcoming',
                        'value' => (string) $upcomingCount,
                        'icon' => 'calendar-outline',
                        'color' => '#2563EB',
                        'soft_color' => '#DBEAFE',
                    ],
                    [
                        'label' => 'Live now',
                        'value' => (string) $liveNowCount,
                        'icon' => 'radio-outline',
                        'color' => '#16A34A',
                        'soft_color' => '#DCFCE7',
                    ],
                    [
                        'label' => 'Capacity',
                        'value' => $capacityRate.'%',
                        'icon' => 'people-outline',
                        'color' => '#F97316',
                        'soft_color' => '#FFEDD5',
                    ],
                ],
                'events' => $events->map(fn (PlatformEvent $event): array => $this->formatCreatorEventCard($event))->values()->all(),
            ],
        ], 200);
    }

    private function formatCreatorEventCard(PlatformEvent $event): array
    {
        $date = $event->starts_at instanceof Carbon ? $event->starts_at : null;
        $status = $this->resolveScreenStatus($event);
        $palette = $this->eventPalette($status);

        return [
            'id' => 'e-'.$event->id,
            'title' => (string) $event->title,
            'date_label' => $date?->format('D, M d') ?? 'TBA',
            'time_label' => $date?->format('g:i A') ?? 'TBA',
            'location' => $this->resolveEventLocation($event),
            'status' => $status,
            'category' => $this->resolveEventCategory($event->category),
            'attendees' => $this->resolveAttendees($event),
            'color' => $palette['color'],
            'soft_color' => $palette['soft_color'],
            'summary' => (string) ($event->description ?: $this->buildEventSummary($event)),
        ];
    }

    private function resolveScreenStatus(PlatformEvent $event): string
    {
        if ($this->isLiveNowEvent($event)) {
            return 'Live';
        }

        if ($event->starts_at instanceof Carbon) {
            if ($event->starts_at->isToday()) {
                return 'Today';
            }

            if ($event->starts_at->isFuture() && $event->starts_at->diffInDays(now()) <= 7) {
                return 'Soon';
            }
        }

        if ($event->ends_at instanceof Carbon && $event->ends_at->isPast()) {
            return 'Past';
        }

        if (($event->starts_at instanceof Carbon && $event->starts_at->isPast()) || ($event->status ?? null) === 'published') {
            return 'Past';
        }

        return 'Soon';
    }

    private function isUpcomingEvent(PlatformEvent $event): bool
    {
        return $event->starts_at instanceof Carbon && $event->starts_at->isFuture();
    }

    private function isLiveNowEvent(PlatformEvent $event): bool
    {
        return $event->starts_at instanceof Carbon
            && $event->starts_at->isPast()
            && ($event->ends_at instanceof Carbon ? $event->ends_at->isFuture() || $event->ends_at->isSameMinute(now()) : true);
    }

    private function resolveEventLocation(PlatformEvent $event): string
    {
        $venueName = trim((string) ($event->venue_name ?? ''));
        $venueAddress = trim((string) ($event->venue_address ?? ''));

        if ($venueName !== '') {
            return $venueName;
        }

        if ($venueAddress !== '') {
            return $venueAddress;
        }

        if (trim((string) ($event->meeting_url ?? '')) !== '') {
            return 'Virtual';
        }

        return 'TBA';
    }

    private function resolveEventCategory(?string $category): string
    {
        $allowed = ['Concert', 'Community', 'Business', 'Workshop'];
        $normalized = ucfirst(strtolower(trim((string) $category)));

        return in_array($normalized, $allowed, true) ? $normalized : 'Business';
    }

    /**
     * @return array<int, string>
     */
    private function resolveAttendees(PlatformEvent $event): array
    {
        $buyers = $event->purchases
            ->filter(fn (EventTicketPurchase $purchase): bool => (string) ($purchase->status ?? '') === 'completed')
            ->map(fn (EventTicketPurchase $purchase) => $purchase->buyer)
            ->filter();

        return $buyers
            ->unique('id')
            ->take(4)
            ->map(function ($buyer): string {
                $name = trim((string) ($buyer->name ?? $buyer->username ?? ''));

                if ($name === '') {
                    return '??';
                }

                $parts = preg_split('/\s+/', $name) ?: [];
                $initials = collect($parts)
                    ->filter()
                    ->take(2)
                    ->map(static fn (string $part): string => strtoupper(substr($part, 0, 1)))
                    ->implode('');

                return str_pad(substr($initials, 0, 2), 2, 'X');
            })
            ->values()
            ->all();
    }

    private function buildSubtitle(PlatformEvent $event): string
    {
        if ($this->isLiveNowEvent($event)) {
            return 'Networking night for builders, creators, and brand partners';
        }

        return 'Event control center for guest flow, revenue, and attendance';
    }

    private function buildSummary(PlatformEvent $event): string
    {
        if ($this->isLiveNowEvent($event)) {
            return 'This event is currently in progress. Track attendance, monitor engagement, and keep an eye on live updates from the control panel.';
        }

        if ($this->isUpcomingEvent($event)) {
            return 'This event is scheduled soon. Review the guest list, check your capacity, and prepare the control panel.';
        }

        return 'This event has ended. Review attendance, revenue, and post-event performance from the control panel.';
    }

    /**
     * @return array<int, array{time:string,title:string,note:string}>
     */
    private function buildSchedule(PlatformEvent $event): array
    {
        $start = $event->starts_at instanceof Carbon ? $event->starts_at : now();

        return [
            [
                'time' => $start->copy()->subMinutes(30)->format('g:i A'),
                'title' => 'Doors open',
                'note' => 'Guest scan and welcome desk are active.',
            ],
            [
                'time' => $start->format('g:i A'),
                'title' => 'Opening remarks',
                'note' => 'Host introduces speakers and agenda.',
            ],
            [
                'time' => $start->copy()->addMinutes(45)->format('g:i A'),
                'title' => 'Panel session',
                'note' => 'Live Q&A with founders and creators.',
            ],
            [
                'time' => $start->copy()->addMinutes(90)->format('g:i A'),
                'title' => 'Networking mixer',
                'note' => 'Attendees move to the lounge area.',
            ],
        ];
    }

    /**
     * @return array<int, array{name:string,initials:string,role:string}>
     */
    private function buildAttendees(PlatformEvent $event): array
    {
        return $event->purchases
            ->filter(fn (EventTicketPurchase $purchase): bool => (string) ($purchase->status ?? '') === 'completed')
            ->map(fn (EventTicketPurchase $purchase) => $purchase->buyer)
            ->filter()
            ->unique('id')
            ->take(4)
            ->values()
            ->map(function ($buyer, int $index): array {
                $name = trim((string) ($buyer->name ?? $buyer->username ?? 'Guest'));
                $parts = preg_split('/\s+/', $name) ?: [];
                $initials = collect($parts)
                    ->filter()
                    ->take(2)
                    ->map(static fn (string $part): string => strtoupper(substr($part, 0, 1)))
                    ->implode('');

                $roles = ['Speaker', 'Host', 'Partner', 'Guest'];

                return [
                    'name' => $name,
                    'initials' => str_pad(substr($initials, 0, 2), 2, 'X'),
                    'role' => $roles[$index] ?? 'Guest',
                ];
            })
            ->all();
    }

    private function accentColor(string $bannerColor): string
    {
        return match ($bannerColor) {
            '#2563EB' => '#60A5FA',
            '#16A34A' => '#4ADE80',
            '#F97316' => '#FDBA74',
            '#7C3AED' => '#C4B5FD',
            default => '#93C5FD',
        };
    }

    private function fallbackBannerUrl(PlatformEvent $event): string
    {
        $slug = Str::slug((string) $event->title);

        return "https://your-cdn.com/events/{$slug}.jpg";
    }

    private function eventPalette(string $status): array
    {
        return match ($status) {
            'Live' => ['color' => '#16A34A', 'soft_color' => '#DCFCE7'],
            'Today' => ['color' => '#2563EB', 'soft_color' => '#DBEAFE'],
            'Past' => ['color' => '#7C3AED', 'soft_color' => '#EDE9FE'],
            default => ['color' => '#F97316', 'soft_color' => '#FFEDD5'],
        };
    }
}
