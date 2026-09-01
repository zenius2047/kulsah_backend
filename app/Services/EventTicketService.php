<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketPurchase;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EventTicketService
{
    public function buildTicketId(): string
    {
        return 'TCK-'.Str::upper(Str::ulid()->toBase32());
    }

    public function buildScanSignature(EventTicketPurchase $purchase, string $ticketId): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $ticketId,
                $purchase->id,
                $purchase->event_id,
                $purchase->buyer_id,
            ]),
            (string) config('app.key')
        );
    }

    public function buildVerificationUrl(string $ticketId, string $signature): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return $baseUrl.'/api/v1/general/events/tickets/verify?'.http_build_query([
            'ticket_id' => $ticketId,
            'signature' => $signature,
        ]);
    }

    public function buildQrCodeUrl(string $verificationUrl): string
    {
        $generator = rtrim((string) config('services.qr_code.generator_url', 'https://api.qrserver.com/v1/create-qr-code/'), '/').'/';

        return $generator.'?'.http_build_query([
            'size' => '300x300',
            'data' => $verificationUrl,
        ]);
    }

    /**
     * @return array<int, EventTicket>
     */
    public function issueTickets(EventTicketPurchase $purchase, Event $event, int $quantity): array
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Ticket quantity must be at least 1.');
        }

        $tickets = [];

        for ($index = 1; $index <= $quantity; $index++) {
            $ticketId = $this->buildTicketId();
            $signature = $this->buildScanSignature($purchase, $ticketId);
            $verificationUrl = $this->buildVerificationUrl($ticketId, $signature);

            $tickets[] = EventTicket::query()->create([
                'event_id' => $event->id,
                'event_ticket_purchase_id' => $purchase->id,
                'buyer_id' => $purchase->buyer_id,
                'ticket_id' => $ticketId,
                'ticket_number' => $index,
                'scan_signature' => $signature,
                'verification_url' => $verificationUrl,
                'qr_code_url' => $this->buildQrCodeUrl($verificationUrl),
                'status' => 'active',
                'metadata' => [
                    'purchase_reference' => $purchase->reference,
                    'ticket_type_code' => $purchase->ticket_type_code,
                    'ticket_type_name' => $purchase->ticket_type_name,
                    'ticket_index' => $index,
                ],
            ]);
        }

        return $tickets;
    }
}
