<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\AdminConsoleRecord;
use App\Models\KulCoinPackage;
use App\Models\SubscriptionPlan;
use App\Models\Event;
use App\Models\EventTicketPurchase;
use App\Models\Subscription;
use App\Models\VideoBoostCampaign;
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
                'video_boost' => $this->fulfillVideoBoost($payment),
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
            'local_amount' => (float) ($payment->metadata['base_amount'] ?? ($payment->amount_minor / 100)),
            'usd_amount' => (float) ($payment->metadata['base_amount'] ?? ($payment->amount_minor / 100)),
            'coin_amount' => $payment->metadata['coin_amount'] ?? $package->coin_amount,
            'bonus_coin_amount' => $payment->metadata['bonus_coin_amount'] ?? $package->bonus_coin_amount,
            'metadata' => ['payment_id' => $payment->id, 'revenue_rule_calculation' => $payment->metadata['revenue_rule_calculation'] ?? null],
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

    private function fulfillVideoBoost(Payment $payment): void
    {
        $campaign = VideoBoostCampaign::query()->lockForUpdate()->findOrFail($payment->payable_id);
        abort_unless((int) $campaign->creator_id === (int) $payment->user_id, 403);
        abort_unless($campaign->status === 'pending_payment', 422, 'This boost campaign is no longer awaiting payment.' );
        if ($campaign->payment_reference && $campaign->payment_reference !== $payment->reference) {
            throw ValidationException::withMessages(['payment' => 'This campaign is already linked to another payment.']);
        }
        $campaign->payment_reference = $payment->reference;
        $campaign->payment_method = 'cash';
        $campaign->status = (bool) data_get($campaign->targeting ?? [], 'requireApproval', data_get(AdminConsoleRecord::payloadFor('video-boosting'), 'requireApproval', true))
            ? 'pending'
            : 'active';
        $campaign->starts_at = $campaign->status === 'active' ? now() : null;
        $campaign->ends_at = $campaign->status === 'active' ? now()->addDays(max(1, (int) data_get($campaign->targeting ?? [], 'durationDays', 1))) : null;
        $campaign->save();
    }

    private function fulfillTicket(Payment $payment): void
    {
        $metadata = $payment->metadata ?? [];
        $event = Event::query()->lockForUpdate()->findOrFail($payment->payable_id);
        $type = is_array($metadata['ticket_type_snapshot'] ?? null) ? $metadata['ticket_type_snapshot'] : collect($event->ticket_types ?? [])->firstWhere('code', $metadata['ticket_type_code'] ?? null);
        $quantity = (int) ($metadata['quantity'] ?? 1);
        if (! $type || $quantity < 1 || (int) $event->capacity - (int) $event->tickets_sold < $quantity) {
            throw ValidationException::withMessages(['ticket' => 'The selected ticket is no longer available.']);
        }

        $ruleCalculation = $payment->metadata['revenue_rule_calculation'] ?? null;
        $organizerNet = $ruleCalculation
            ? (in_array($ruleCalculation['remainingRecipient'] ?? null, ['organizer', 'creator'], true) ? (float) $ruleCalculation['recipientNet'] : 0)
            : null;
        $purchase = EventTicketPurchase::query()->firstOrCreate(
            ['reference' => 'pay:'.$payment->reference],
            ['event_id' => $event->id, 'buyer_id' => $payment->user_id, 'ticket_type_code' => $type['code'], 'ticket_type_name' => $type['name'], 'ticket_type_snapshot' => $type, 'quantity' => $quantity, 'unit_price' => (float) ($metadata['unit_price'] ?? $type['price']), 'total_amount' => (float) ($metadata['unit_price'] ?? $type['price']) * $quantity, 'currency' => $metadata['event_currency'] ?? $event->currency, 'status' => 'completed', 'metadata' => ['payment_id' => $payment->id, 'revenue_rule_calculation' => $ruleCalculation, 'organizer_net_amount' => $organizerNet], 'purchased_at' => now()]
        );
        if ($purchase->wasRecentlyCreated) {
            $this->eventTicketService->issueTickets($purchase, $event, $quantity);
            $event->increment('tickets_sold', $quantity);
        }
    }
}
