<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use App\Models\User;
use App\Services\EventTicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'admin', 'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $media = DemoMedia::videos();

            $danceTickets = [
                ['id' => 1, 'code' => 'general', 'name' => 'General Admission', 'description' => 'Floor access and the full showcase.', 'price' => 85, 'quantity' => 180, 'sold_quantity' => 28, 'minimum_per_order' => 1, 'maximum_per_order' => 6],
                ['id' => 2, 'code' => 'vip', 'name' => 'Front Row', 'description' => 'Reserved front-row seating and creator meet-and-greet.', 'price' => 180, 'quantity' => 40, 'sold_quantity' => 8, 'minimum_per_order' => 1, 'maximum_per_order' => 4],
            ];
            $workshopTickets = [
                ['id' => 1, 'code' => 'stream', 'name' => 'Live Stream', 'description' => 'Interactive live session plus replay.', 'price' => 12, 'quantity' => 500, 'sold_quantity' => 64, 'minimum_per_order' => 1, 'maximum_per_order' => 2],
            ];
            $screeningTickets = [
                ['id' => 1, 'code' => 'community', 'name' => 'Community Pass', 'description' => 'Outdoor screening and filmmaker Q&A.', 'price' => 30, 'quantity' => 120, 'sold_quantity' => 41, 'minimum_per_order' => 1, 'maximum_per_order' => 8],
            ];

            $events = collect();
            $events->put('dance', Event::query()->updateOrCreate(
                ['user_id' => $users->get('creator')->id, 'title' => 'Coastal Moves Live Showcase'],
                [
                    'description' => 'An evening of dance, live percussion, and performances from the Coastal Moves challenge finalists.',
                    'category' => 'Dance & Culture',
                    'venue_type' => 'physical',
                    'venue_name' => 'Alliance Française Accra',
                    'venue_address' => '9 Casely Hayford Road, Accra, Ghana',
                    'meeting_url' => null,
                    'starts_at' => now()->addDays(18)->setTime(18, 30),
                    'ends_at' => now()->addDays(18)->setTime(22, 0),
                    'timezone' => 'Africa/Accra',
                    'capacity' => 220,
                    'currency' => 'GHS',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/coastal-moves.jpg',
                    'cover_image_url' => $media['coastal-dance']['poster_url'],
                    'ticket_types' => $danceTickets,
                    'status' => 'published',
                    'tickets_sold' => 36,
                ],
            ));
            $events->put('workshop', Event::query()->updateOrCreate(
                ['user_id' => $users->get('zuri.moves')->id, 'title' => 'Build Your 30-Second Choreography'],
                [
                    'description' => 'A live online workshop on musicality, framing, and building movement for short-form video.',
                    'category' => 'Workshop',
                    'venue_type' => 'online',
                    'venue_name' => 'Kulsah Live',
                    'venue_address' => null,
                    'meeting_url' => 'https://meet.example.com/kulsah-demo-workshop',
                    'starts_at' => now()->addDays(7)->setTime(16, 0),
                    'ends_at' => now()->addDays(7)->setTime(18, 0),
                    'timezone' => 'Africa/Nairobi',
                    'capacity' => 500,
                    'currency' => 'USD',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/choreography-workshop.jpg',
                    'cover_image_url' => $media['coastal-style']['poster_url'],
                    'ticket_types' => $workshopTickets,
                    'status' => 'published',
                    'tickets_sold' => 64,
                ],
            ));
            $events->put('screening', Event::query()->updateOrCreate(
                ['user_id' => $users->get('kwame.frames')->id, 'title' => 'Stories by the Water: Community Screening'],
                [
                    'description' => 'A relaxed outdoor screening of short travel stories followed by a practical filmmaker Q&A.',
                    'category' => 'Film & Storytelling',
                    'venue_type' => 'physical',
                    'venue_name' => 'Takoradi Cultural Centre',
                    'venue_address' => 'Harbour Road, Takoradi, Ghana',
                    'meeting_url' => null,
                    'starts_at' => now()->addDays(30)->setTime(19, 0),
                    'ends_at' => now()->addDays(30)->setTime(21, 30),
                    'timezone' => 'Africa/Accra',
                    'capacity' => 120,
                    'currency' => 'GHS',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/stories-by-water.jpg',
                    'cover_image_url' => $media['ship-story']['poster_url'],
                    'ticket_types' => $screeningTickets,
                    'status' => 'published',
                    'tickets_sold' => 41,
                ],
            ));

            $events->put('ongoing', Event::query()->updateOrCreate(
                ['user_id' => $users->get('naledi.fit')->id, 'title' => 'Weekend Mobility Marathon'],
                [
                    'description' => 'An always-on demo event for testing live event discovery, booking, and creator dashboards.',
                    'category' => 'Fitness & Wellness',
                    'venue_type' => 'online',
                    'venue_name' => 'Kulsah Live Studio',
                    'venue_address' => null,
                    'meeting_url' => 'https://meet.example.com/kulsah-mobility-marathon',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addDay(),
                    'timezone' => 'Africa/Johannesburg',
                    'capacity' => 300,
                    'currency' => 'USD',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/mobility-marathon.jpg',
                    'cover_image_url' => $media['naledi-mobility']['poster_url'],
                    'ticket_types' => [[
                        'id' => 1, 'code' => 'live', 'name' => 'Live Access',
                        'description' => 'Join the live mobility room.', 'price' => 8,
                        'quantity' => 300, 'sold_quantity' => 18,
                        'minimum_per_order' => 1, 'maximum_per_order' => 2,
                    ]],
                    'status' => 'published',
                    'tickets_sold' => 18,
                ],
            ));
            $events->put('past', Event::query()->updateOrCreate(
                ['user_id' => $users->get('tunde.creates')->id, 'title' => 'Comedy Cuts Premiere Night'],
                [
                    'description' => 'A completed screening retained for ticket history, check-in, and creator analytics demos.',
                    'category' => 'Comedy & Film',
                    'venue_type' => 'physical',
                    'venue_name' => 'Freedom Park Lagos',
                    'venue_address' => '1 Hospital Road, Lagos Island, Nigeria',
                    'meeting_url' => null,
                    'starts_at' => now()->subDays(12)->setTime(18, 0),
                    'ends_at' => now()->subDays(12)->setTime(21, 0),
                    'timezone' => 'Africa/Lagos',
                    'capacity' => 100,
                    'currency' => 'NGN',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/comedy-cuts.jpg',
                    'cover_image_url' => $media['tunde-behind-scenes']['poster_url'],
                    'ticket_types' => [[
                        'id' => 1, 'code' => 'general', 'name' => 'General Admission',
                        'description' => 'Screening and creator Q&A.', 'price' => 5000,
                        'quantity' => 100, 'sold_quantity' => 73,
                        'minimum_per_order' => 1, 'maximum_per_order' => 4,
                    ]],
                    'status' => 'published',
                    'tickets_sold' => 73,
                ],
            ));
            $events->put('draft', Event::query()->updateOrCreate(
                ['user_id' => $users->get('amina.designs')->id, 'title' => 'Small Space Design Lab'],
                [
                    'description' => 'An unpublished creator draft for testing edit, preview, and publish workflows.',
                    'category' => 'Design & Lifestyle',
                    'venue_type' => 'online',
                    'venue_name' => 'Kulsah Live',
                    'venue_address' => null,
                    'meeting_url' => 'https://meet.example.com/kulsah-small-space-lab',
                    'starts_at' => now()->addDays(45)->setTime(17, 0),
                    'ends_at' => now()->addDays(45)->setTime(19, 0),
                    'timezone' => 'Africa/Dakar',
                    'capacity' => 80,
                    'currency' => 'XOF',
                    'cover_image_disk' => 'remote-demo',
                    'cover_image_key' => 'demo/events/small-space-design.jpg',
                    'cover_image_url' => $media['amina-moodboard']['poster_url'],
                    'ticket_types' => [[
                        'id' => 1, 'code' => 'workshop', 'name' => 'Workshop Seat',
                        'description' => 'Interactive design workshop.', 'price' => 6000,
                        'quantity' => 80, 'sold_quantity' => 0,
                        'minimum_per_order' => 1, 'maximum_per_order' => 2,
                    ]],
                    'status' => 'draft',
                    'tickets_sold' => 0,
                ],
            ));

            $this->seedPurchaseAndTickets($events->get('dance'), $users->get('fan'), $users->get('admin'), $danceTickets[0]);
            $this->seedPurchaseAndTickets($events->get('workshop'), $users->get('fans'), $users->get('admin'), $workshopTickets[0], 'EVT-DEMO-0002');
            $this->seedPurchaseAndTickets(
                $events->get('past'),
                $users->get('fan'),
                $users->get('admin'),
                $events->get('past')->ticket_types[0],
                'EVT-DEMO-0003',
                1,
                true,
            );
        });
    }

    private function seedPurchaseAndTickets(
        Event $event,
        User $buyer,
        User $admin,
        array $ticketType,
        string $reference = 'EVT-DEMO-0001',
        ?int $quantity = null,
        bool $checkedIn = false,
    ): void {
        $quantity ??= $reference === 'EVT-DEMO-0001' ? 2 : 1;
        $purchase = EventTicketPurchase::query()->updateOrCreate(
            ['reference' => $reference],
            [
                'event_id' => $event->id,
                'buyer_id' => $buyer->id,
                'ticket_type_code' => $ticketType['code'],
                'ticket_type_name' => $ticketType['name'],
                'ticket_type_snapshot' => $ticketType,
                'quantity' => $quantity,
                'unit_price' => $ticketType['price'],
                'total_amount' => $ticketType['price'] * $quantity,
                'currency' => $event->currency,
                'status' => 'completed',
                'idempotency_key' => strtolower($reference).'-purchase',
                'metadata' => ['seeded' => true, 'payment_method' => 'demo_wallet'],
                'purchased_at' => now()->subDays(3),
            ],
        );

        $ticketService = app(EventTicketService::class);
        for ($ticketNumber = 1; $ticketNumber <= $quantity; $ticketNumber++) {
            $ticketId = sprintf('TCK-%s-%02d', str_replace('EVT-DEMO-', 'DEMO', $reference), $ticketNumber);
            $signature = $ticketService->buildScanSignature($purchase, $ticketId);
            $verificationUrl = $ticketService->buildVerificationUrl($ticketId, $signature);
            EventTicket::query()->updateOrCreate(
                ['ticket_id' => $ticketId],
                [
                    'event_id' => $event->id,
                    'event_ticket_purchase_id' => $purchase->id,
                    'buyer_id' => $buyer->id,
                    'ticket_number' => $ticketNumber,
                    'scan_signature' => $signature,
                    'verification_url' => $verificationUrl,
                    'qr_code_url' => $ticketService->buildQrCodeUrl($verificationUrl),
                    'status' => $checkedIn ? 'used' : 'active',
                    'verified_at' => $checkedIn ? $event->starts_at->copy()->addHour() : null,
                    'verified_by' => $checkedIn ? $admin->id : null,
                    'metadata' => [
                        'seeded' => true,
                        'purchase_reference' => $reference,
                        'ticket_type_code' => $ticketType['code'],
                        'issued_by' => $admin->id,
                    ],
                ],
            );
        }
    }
}
