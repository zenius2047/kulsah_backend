<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use App\Models\Role;
use App\Models\User;
use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_create_published_event_with_cover_and_ticket_types(): void
    {
        $creator = User::factory()->create([
            'username' => 'event_creator',
        ]);

        Storage::fake('events-media');
        config([
            'video.storage_disk' => 'events-media',
        ]);

        $this->mock(CloudinaryService::class, function ($mock): void {
            $mock->shouldReceive('uploadImageFromS3Key')
                ->once()
                ->andReturn([
                    'cdn_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'rendered_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'stream_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'streaming_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'cloudinary_public_id' => 'kulsah/events/sample',
                    'cloudinary_asset_id' => 'asset_123',
                    'thumbnail_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'poster_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg',
                    'metadata' => ['resource_type' => 'image'],
                ]);
        });

        $response = $this->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->post('/api/v1/creator/events', [
                'title' => 'Afrobeats Live Experience',
                'description' => 'A live performance featuring new songs and special guests.',
                'category' => 'music',
                'venue_type' => 'physical',
                'venue_name' => 'National Theatre',
                'venue_address' => 'Liberation Road, Accra, Ghana',
                'starts_at' => '2026-08-20T18:00:00Z',
                'ends_at' => '2026-08-20T21:00:00Z',
                'timezone' => 'Africa/Accra',
                'capacity' => 500,
                'currency' => 'GHS',
                'ticket_types' => [
                    [
                        'name' => 'Regular',
                        'description' => 'General admission',
                        'price' => 100,
                        'quantity' => 400,
                    ],
                    [
                        'name' => 'VIP',
                        'description' => 'Priority seating and backstage access',
                        'price' => 250,
                        'quantity' => 100,
                    ],
                ],
                'cover_image' => UploadedFile::fake()->image('event-cover.jpg'),
                'status' => 'published',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Event created successfully.')
            ->assertJsonPath('data.title', 'Afrobeats Live Experience')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.ticket_types.0.name', 'Regular')
            ->assertJsonPath('data.ticket_types.0.available_quantity', 400)
            ->assertJsonPath('data.cover_image_url', 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/events/sample.jpg');

        $this->assertDatabaseHas('events', [
            'user_id' => $creator->id,
            'title' => 'Afrobeats Live Experience',
            'status' => 'published',
            'capacity' => 500,
            'tickets_sold' => 0,
        ]);
    }

    public function test_fan_can_purchase_event_ticket(): void
    {
        $creator = User::factory()->create([
            'username' => 'event_owner',
        ]);
        $creatorRole = Role::query()->create(['name' => 'creator']);
        $creator->roles()->attach($creatorRole);
        $buyer = User::factory()->create([
            'username' => 'event_buyer',
        ]);

        $event = Event::create([
            'user_id' => $creator->id,
            'title' => 'Afrobeats Live Experience',
            'description' => 'A live performance featuring new songs and special guests.',
            'category' => 'music',
            'venue_type' => 'physical',
            'venue_name' => 'National Theatre',
            'venue_address' => 'Liberation Road, Accra, Ghana',
            'meeting_url' => null,
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHours(3),
            'timezone' => 'Africa/Accra',
            'capacity' => 500,
            'currency' => 'GHS',
            'cover_image_disk' => null,
            'cover_image_key' => null,
            'cover_image_url' => 'https://example.com/event.jpg',
            'ticket_types' => [
                [
                    'code' => 'regular',
                    'name' => 'Regular',
                    'description' => 'General admission',
                    'price' => 100,
                    'quantity' => 400,
                    'sold_quantity' => 0,
                ],
                [
                    'code' => 'vip',
                    'name' => 'VIP',
                    'description' => 'Priority seating and backstage access',
                    'price' => 250,
                    'quantity' => 100,
                    'sold_quantity' => 0,
                ],
            ],
            'status' => 'published',
            'tickets_sold' => 0,
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson("/api/v1/general/events/{$event->id}/tickets/purchase", [
                'ticket_type_code' => 'regular',
                'quantity' => 2,
                'idempotency_key' => 'event-purchase-1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Event ticket purchased successfully.')
            ->assertJsonPath('data.purchase.quantity', 2)
            ->assertJsonPath('data.purchase.total_amount', '200.0000')
            ->assertJsonPath('data.event.stats.tickets_sold', 2)
            ->assertJsonPath('data.event.ticket_types.0.sold_quantity', 2)
            ->assertJsonPath('data.purchase.tickets.0.ticket_number', 1)
            ->assertJsonPath('data.purchase.tickets.0.status', 'active');

        $this->assertDatabaseHas('event_ticket_purchases', [
            'event_id' => $event->id,
            'buyer_id' => $buyer->id,
            'ticket_type_code' => 'regular',
            'quantity' => 2,
            'total_amount' => 200,
            'currency' => 'GHS',
            'status' => 'completed',
        ]);

        $this->assertDatabaseCount('event_tickets', 2);

        $purchase = EventTicketPurchase::query()->with('tickets')->firstOrFail();
        $this->assertCount(2, $purchase->tickets);

        $firstTicket = EventTicket::query()->firstOrFail();
        $this->assertSame('active', $firstTicket->status);
        $this->assertStringStartsWith('TCK-', $firstTicket->ticket_id);
        $this->assertStringContainsString('/api/v1/general/events/tickets/verify', $firstTicket->verification_url);
        $this->assertStringStartsWith('https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=', $firstTicket->qr_code_url);

        $verifyResponse = $this->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson('/api/v1/general/events/tickets/verify', [
                'ticket_id' => $firstTicket->ticket_id,
                'signature' => $firstTicket->scan_signature,
            ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('message', 'Ticket verified successfully.')
            ->assertJsonPath('data.ticket_id', $firstTicket->ticket_id)
            ->assertJsonPath('data.status', 'used');

        $event->refresh();
        $this->assertSame(2, (int) $event->tickets_sold);
        $this->assertSame(2, (int) data_get($event->ticket_types, '0.sold_quantity'));
    }
}
