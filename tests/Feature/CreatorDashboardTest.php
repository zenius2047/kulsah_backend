<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_dashboard_returns_overview_scans_locations_and_notifications(): void
    {
        config()->set('logging.default', 'null');

        $walletService = app(WalletService::class);

        $creator = User::factory()->create([
            'name' => 'Melvin Sam.',
            'email' => 'dashboard@example.com',
            'username' => 'melvin_sam',
        ]);

        $creatorWallet = $walletService->getOrCreateUserWallet($creator);
        $creatorWallet->forceFill([
            'available_balance_usd' => 10824,
            'pending_balance_usd' => 0,
            'held_balance_usd' => 0,
        ])->save();

        $completedEvent = Event::create([
            'user_id' => $creator->id,
            'title' => 'Insta Home Makeover',
            'description' => 'Completed event',
            'category' => 'Lifestyle',
            'venue_type' => 'virtual',
            'venue_name' => null,
            'venue_address' => null,
            'meeting_url' => 'https://example.com/live/insta-home-makeover',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
            'timezone' => 'UTC',
            'capacity' => 100,
            'currency' => 'USD',
            'ticket_types' => [
                [
                    'id' => 1,
                    'code' => 'general',
                    'name' => 'General',
                    'price' => 50,
                    'quantity' => 100,
                    'sold_quantity' => 3,
                ],
            ],
            'status' => 'published',
            'tickets_sold' => 3,
        ]);

        $runningEvent = Event::create([
            'user_id' => $creator->id,
            'title' => 'Live Creator Sprint',
            'description' => 'Running event',
            'category' => 'Business',
            'venue_type' => 'virtual',
            'venue_name' => null,
            'venue_address' => null,
            'meeting_url' => 'https://example.com/live/creator-sprint',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'timezone' => 'UTC',
            'capacity' => 50,
            'currency' => 'USD',
            'ticket_types' => [
                [
                    'id' => 1,
                    'code' => 'general',
                    'name' => 'General',
                    'price' => 40,
                    'quantity' => 50,
                    'sold_quantity' => 1,
                ],
            ],
            'status' => 'published',
            'tickets_sold' => 1,
        ]);

        Event::create([
            'user_id' => $creator->id,
            'title' => 'Draft Event',
            'description' => 'Not published yet',
            'category' => 'Lifestyle',
            'venue_type' => 'virtual',
            'venue_name' => null,
            'venue_address' => null,
            'meeting_url' => 'https://example.com/live/draft',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'timezone' => 'UTC',
            'capacity' => 20,
            'currency' => 'USD',
            'ticket_types' => [
                [
                    'id' => 1,
                    'code' => 'general',
                    'name' => 'General',
                    'price' => 25,
                    'quantity' => 20,
                    'sold_quantity' => 0,
                ],
            ],
            'status' => 'draft',
            'tickets_sold' => 0,
        ]);

        $buyerOne = User::factory()->create([
            'name' => 'Ava Thompson',
            'username' => 'ava_thompson',
            'email' => 'ava@example.com',
            'location' => 'Lagos, Nigeria',
        ]);

        $buyerTwo = User::factory()->create([
            'name' => 'Jordan Miles',
            'username' => 'jordan_miles',
            'email' => 'jordan@example.com',
            'location' => 'Nairobi, Kenya',
        ]);

        $purchaseOne = EventTicketPurchase::create([
            'event_id' => $completedEvent->id,
            'buyer_id' => $buyerOne->id,
            'ticket_type_code' => 'general',
            'ticket_type_name' => 'General',
            'ticket_type_snapshot' => [
                'id' => 1,
                'code' => 'general',
                'name' => 'General',
                'price' => 50,
            ],
            'quantity' => 2,
            'unit_price' => 50,
            'total_amount' => 100,
            'currency' => 'USD',
            'status' => 'completed',
            'reference' => (string) Str::uuid(),
            'metadata' => ['source' => 'test'],
            'purchased_at' => now()->subMonth(),
        ]);

        $purchaseTwo = EventTicketPurchase::create([
            'event_id' => $completedEvent->id,
            'buyer_id' => $buyerTwo->id,
            'ticket_type_code' => 'general',
            'ticket_type_name' => 'General',
            'ticket_type_snapshot' => [
                'id' => 1,
                'code' => 'general',
                'name' => 'General',
                'price' => 50,
            ],
            'quantity' => 1,
            'unit_price' => 50,
            'total_amount' => 50,
            'currency' => 'USD',
            'status' => 'completed',
            'reference' => (string) Str::uuid(),
            'metadata' => ['source' => 'test'],
            'purchased_at' => now()->subDays(10),
        ]);

        EventTicket::create([
            'event_id' => $completedEvent->id,
            'event_ticket_purchase_id' => $purchaseOne->id,
            'buyer_id' => $buyerOne->id,
            'ticket_id' => 'TCK-ONE',
            'ticket_number' => 1,
            'scan_signature' => 'sig-one',
            'verification_url' => 'https://example.com/verify/one',
            'qr_code_url' => 'https://example.com/qr/one',
            'status' => 'used',
            'verified_at' => now()->subMinutes(2),
            'verified_by' => $creator->id,
            'metadata' => ['purchase_reference' => $purchaseOne->reference],
        ]);

        EventTicket::create([
            'event_id' => $completedEvent->id,
            'event_ticket_purchase_id' => $purchaseTwo->id,
            'buyer_id' => $buyerTwo->id,
            'ticket_id' => 'TCK-TWO',
            'ticket_number' => 1,
            'scan_signature' => 'sig-two',
            'verification_url' => 'https://example.com/verify/two',
            'qr_code_url' => 'https://example.com/qr/two',
            'status' => 'used',
            'verified_at' => now()->subMinutes(10),
            'verified_by' => $creator->id,
            'metadata' => ['purchase_reference' => $purchaseTwo->reference],
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'dashboard.new_fan_scanned',
            'notifiable_type' => User::class,
            'notifiable_id' => $creator->id,
            'data' => json_encode([
                'type' => 'dashboard.new_fan_scanned',
                'title' => 'New fan scanned in',
                'message' => 'Ava Thompson checked into Insta Home Makeover',
            ]),
            'read_at' => null,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/creator/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.user.name', 'Melvin Sam.')
            ->assertJsonPath('data.user.email', 'dashboard@example.com')
            ->assertJsonPath('data.overview.events_created', 3)
            ->assertJsonPath('data.overview.events_completed', 1)
            ->assertJsonPath('data.overview.events_running', 1)
            ->assertJsonPath('data.overview.earnings_total', 150)
            ->assertJsonPath('data.overview.balance', 10824)
            ->assertJsonPath('data.recent_scanned.0.name', 'Ava Thompson')
            ->assertJsonPath('data.recent_scanned.0.event', 'Insta Home Makeover')
            ->assertJsonPath('data.notifications.0.title', 'New fan scanned in')
            ->assertJsonPath('data.notifications.0.read', false)
            ->assertJsonPath('data.fan_location.callouts.top_locations.0.location', 'Lagos, Nigeria')
            ->assertJsonPath('data.fan_location.callouts.top_locations.0.buyers', 1)
            ->assertJsonPath('data.earning_graph.callouts.earnings_total', 150);
    }
}


