<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreatorDashboardController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    public function show(Request $request)
    {
        $creator = $request->user();
        $cacheKey = "creator-dashboard:{$creator->id}";

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($creator): array {
            $wallet = $this->walletService->getOrCreateUserWallet($creator);

            $eventsQuery = Event::query()->where('user_id', $creator->id);
            $completedEventsQuery = (clone $eventsQuery)
                ->where('status', 'published')
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', now());
            $runningEventsQuery = (clone $eventsQuery)
                ->where('status', 'published')
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now());

            $purchaseQuery = EventTicketPurchase::query()
                ->where('status', 'completed')
                ->whereHas('event', function ($query) use ($creator): void {
                    $query->where('user_id', $creator->id);
                });

            $months = $this->buildMonthLabels(10);
            $monthlyEarnings = array_fill(0, count($months), 0.0);
            $monthlyOrders = array_fill(0, count($months), 0);

            $purchaseQuery->get(['id', 'total_amount', 'quantity', 'purchased_at', 'created_at'])
                ->each(function (EventTicketPurchase $purchase) use (&$monthlyEarnings, &$monthlyOrders, $months): void {
                    $date = $purchase->purchased_at ?? $purchase->created_at;

                    if (! $date) {
                        return;
                    }

                    $monthKey = Carbon::parse($date)->format('Y-m');
                    $index = array_search($monthKey, array_map(static fn (array $month) => $month['key'], $months), true);

                    if ($index === false) {
                        return;
                    }

                    $monthlyEarnings[$index] += (float) $purchase->total_amount;
                    $monthlyOrders[$index] += (int) $purchase->quantity;
                });

            $locationExpression = "COALESCE(NULLIF(TRIM(users.location), ''), 'Unknown')";
            $fanLocations = DB::table('event_ticket_purchases')
                ->join('events', 'events.id', '=', 'event_ticket_purchases.event_id')
                ->join('users', 'users.id', '=', 'event_ticket_purchases.buyer_id')
                ->where('events.user_id', $creator->id)
                ->where('event_ticket_purchases.status', 'completed')
                ->selectRaw("{$locationExpression} as location")
                ->selectRaw('COUNT(DISTINCT users.id) as buyer_count')
                ->selectRaw('SUM(event_ticket_purchases.quantity) as ticket_count')
                ->selectRaw('SUM(event_ticket_purchases.total_amount) as earnings_total')
                ->groupByRaw($locationExpression)
                ->orderByDesc('ticket_count')
                ->limit(10)
                ->get()
                ->map(function ($row): array {
                    return [
                        'location' => (string) $row->location,
                        'buyers' => (int) $row->buyer_count,
                        'tickets' => (int) $row->ticket_count,
                        'earnings_total' => round((float) $row->earnings_total, 2),
                    ];
                })
                ->values()
                ->all();

            $recentScanned = EventTicket::query()
                ->with(['event:id,title,user_id', 'buyer:id,name,username,avatar,location'])
                ->whereHas('event', function ($query) use ($creator): void {
                    $query->where('user_id', $creator->id);
                })
                ->where('status', 'used')
                ->whereNotNull('verified_at')
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(function (EventTicket $ticket): array {
                    $buyerName = $ticket->buyer?->name
                        ?: $ticket->buyer?->username
                        ?: 'Unknown fan';

                    return [
                        'name' => $buyerName,
                        'event' => $ticket->event?->title,
                        'time' => optional($ticket->verified_at ?? $ticket->updated_at)->diffForHumans(),
                        'avatar_url' => $ticket->buyer?->avatar,
                        'avatar_color' => $this->avatarColor($buyerName),
                        'location' => $ticket->buyer?->location,
                    ];
                })
                ->values()
                ->all();

            $notifications = $creator->notifications()
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($notification): array {
                    $data = is_array($notification->data) ? $notification->data : [];
                    $title = (string) ($data['title'] ?? $data['subject'] ?? $this->titleFromNotificationType((string) $notification->type));

                    return [
                        'id' => $notification->id,
                        'title' => $title,
                        'message' => (string) ($data['message'] ?? $data['body'] ?? $data['text'] ?? ''),
                        'time' => optional($notification->created_at)->diffForHumans(),
                        'read' => $notification->read_at !== null,
                        'type' => $data['type'] ?? $notification->type,
                    ];
                })
                ->values()
                ->all();

            return [
                'data' => [
                    'user' => [
                        'name' => $creator->name,
                        'email' => $creator->email,
                    ],
                    'overview' => [
                        'events_created' => $eventsQuery->count(),
                        'events_completed' => $completedEventsQuery->count(),
                        'earnings_total' => round((float) $purchaseQuery->sum('total_amount'), 2),
                        'balance' => round((float) $wallet->available_balance_usd, 2),
                        'events_running' => $runningEventsQuery->count(),
                    ],
                    'fan_location' => [
                        'months' => array_map(static fn (array $month): string => $month['label'], $months),
                        'series' => [
                            'orders' => $monthlyOrders,
                            'earnings' => array_map(static fn (float $value): float => round($value, 2), $monthlyEarnings),
                        ],
                        'callouts' => [
                            'top_locations' => $fanLocations,
                        ],
                    ],
                    'earning_graph' => [
                        'months' => array_map(static fn (array $month): string => $month['label'], $months),
                        'series' => [
                            'orders' => $monthlyOrders,
                            'earnings' => array_map(static fn (float $value): float => round($value, 2), $monthlyEarnings),
                        ],
                        'callouts' => [
                            'earnings_total' => round((float) $purchaseQuery->sum('total_amount'), 2),
                            'orders_total' => array_sum($monthlyOrders),
                        ],
                    ],
                    'recent_scanned' => $recentScanned,
                    'notifications' => $notifications,
                ],
            ];
        });

        return response()->json($payload);
    }

    /**
     * @return array<int, array{key:string,label:string}>
     */
    private function buildMonthLabels(int $monthsBack = 10): array
    {
        $labels = [];

        for ($index = $monthsBack - 1; $index >= 0; $index--) {
            $date = now()->startOfMonth()->subMonths($index);
            $labels[] = [
                'key' => $date->format('Y-m'),
                'label' => $date->format('M'),
            ];
        }

        return $labels;
    }

    private function avatarColor(string $seed): string
    {
        $palette = [
            '#DB2777',
            '#2563EB',
            '#F97316',
            '#16A34A',
            '#7C3AED',
            '#0F766E',
            '#DC2626',
            '#D97706',
        ];

        $hash = abs(crc32(mb_strtolower(trim($seed))));

        return $palette[$hash % count($palette)];
    }

    private function titleFromNotificationType(string $type): string
    {
        $slug = str_replace(['.', '_'], ' ', $type);

        return trim(ucwords($slug)) ?: 'Notification';
    }
}
