<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\KulCoinPackage;
use App\Models\SubscriptionPlan;
use App\Models\Event;
use App\Models\EventTicketPurchase;
use App\Models\Subscription;
use App\Services\EventTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentFulfillmentService
{
    public function __construct(
        private readonly KulCoinService $kulCoinService,
        private readonly EventTicketService $eventTicketService,
    ) {
    }

    public function fulfill(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->fulfilled_at) {
                return $payment;
            }

            match ($payment->purpose) {
                'kulcoin' => $this->fulfillKulCoin($payment),
                'subscription' => $this->fulfillSubscription($payment),
                'event_ticket' => $this->fulfillTicket($payment),
                default => throw ValidationException::withMessages(['purpose' => 'This payment purpose is not supported yet.']),
            };

            $payment->forceFill(['status' => 'successful', 'fulfilled_at' => now()])->save();
            return $payment->fresh();
        });
    }

    private function fulfillKulCoin(Payment $payment): void
    {
        $package = KulCoinPackage::query()->findOrFail($payment->payable_id);
        $this->kulCoinService->purchasePackage($payment->user, $package, [
            'idempotency_key' => 'payment:'.$payment->reference,
            'payment_reference' => $payment->reference,
            'local_currency' => $payment->currency,
            'local_amount' => $payment->amount_minor / 100,
            'usd_amount' => $payment->amount_minor / 100,
            'metadata' => ['payment_id' => $payment->id],
        ], $payment->user);
    }

    private function fulfillSubscription(Payment $payment): void
    {
        $plan = SubscriptionPlan::query()->findOrFail($payment->payable_id);
        $existing = Subscription::query()->where('subscriber_id', $payment->user_id)->where('creator_id', $plan->creator_id)->first();
        if ($existing?->status === 'blocked') {
            throw ValidationException::withMessages(['subscription' => 'You are blocked from this creator.']);
        }

        $start = $existing?->expires_at?->isFuture() ? $existing->expires_at->copy() : now();
        $expires = match ($plan->billing_interval) {
            'weekly' => $start->copy()->addWeek(),
            'yearly', 'annual' => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
        Subscription::query()->updateOrCreate(
            ['subscriber_id' => $payment->user_id, 'creator_id' => $plan->creator_id],
            ['subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => $start, 'expires_at' => $expires, 'blocked_at' => null, 'blocked_by' => null, 'blocked_reason' => null]
        );
    }

    private function fulfillTicket(Payment $payment): void
    {
        $metadata = $payment->metadata ?? [];
        $event = Event::query()->lockForUpdate()->findOrFail($payment->payable_id);
        $type = collect($event->ticket_types ?? [])->firstWhere('code', $metadata['ticket_type_code'] ?? null);
        $quantity = (int) ($metadata['quantity'] ?? 1);
        if (! $type || $quantity < 1 || (int) $event->capacity - (int) $event->tickets_sold < $quantity) {
            throw ValidationException::withMessages(['ticket' => 'The selected ticket is no longer available.']);
        }

        $purchase = EventTicketPurchase::query()->firstOrCreate(
            ['reference' => 'pay:'.$payment->reference],
            ['event_id' => $event->id, 'buyer_id' => $payment->user_id, 'ticket_type_code' => $type['code'], 'ticket_type_name' => $type['name'], 'ticket_type_snapshot' => $type, 'quantity' => $quantity, 'unit_price' => $type['price'], 'total_amount' => $type['price'] * $quantity, 'currency' => $event->currency, 'status' => 'completed', 'metadata' => ['payment_id' => $payment->id], 'purchased_at' => now()]
        );
        if ($purchase->wasRecentlyCreated) {
            $this->eventTicketService->issueTickets($purchase, $event, $quantity);
            $event->increment('tickets_sold', $quantity);
        }
    }
}
