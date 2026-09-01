<?php

namespace App\Services;

use App\Models\Event;
use App\Models\KulCoinPackage;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Events\PaymentStatusChanged;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly MoneyService $money,
        private readonly PaymentFulfillmentService $fulfillment,
    ) {
    }

    public function initialize(User $user, array $input): Payment
    {
        $purpose = $input['purpose'];
        [$payable, $amount, $currency, $metadata] = $this->resolvePurchase($user, $purpose, $input);

        $existing = Payment::query()
            ->where('user_id', $user->id)
            ->where('payable_type', $payable::class)
            ->where('payable_id', $payable->getKey())
            ->where('channel', $input['method'])
            ->whereIn('status', ['pending', 'processing'])
            ->when(! empty($input['idempotency_key']), fn ($query) => $query->where('idempotency_key', $input['idempotency_key']))
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! empty($input['idempotency_key'])) {
            $existing = Payment::query()->where('user_id', $user->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'reference' => 'KUL-'.Str::upper(Str::random(24)),
            'provider' => 'paystack',
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'purpose' => $purpose,
            'payable_type' => $payable::class,
            'payable_id' => $payable->getKey(),
            'amount_minor' => $this->money->toMinorUnits($amount, $currency),
            'currency' => strtoupper($currency),
            'status' => 'pending',
            'channel' => $input['method'],
            'metadata' => $metadata,
        ]);

        $payload = [
            'email' => $user->email,
            'amount' => $payment->amount_minor,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'metadata' => ['payment_id' => $payment->id, 'purpose' => $purpose, 'user_id' => $user->id] + $metadata,
        ];
        if (config('paystack.callback_url')) {
            $payload['callback_url'] = config('paystack.callback_url');
        }
        if ($input['method'] === 'mobile_money') {
            $payload['mobile_money'] = ['phone' => $input['phone'], 'provider' => $input['provider']];
        } else {
            $payload['channels'] = ['card'];
        }

        try {
            $provider = $this->paystack->initialize($payload, $input['method']);
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'failed', 'failure_reason' => $exception->getMessage()]);
            throw $exception;
        }

        $providerStatus = (string) ($provider['status'] ?? 'pending');
        $payment->update([
            'provider_reference' => $provider['reference'] ?? $payment->reference,
            'provider_status' => $providerStatus,
            'provider_response' => $provider,
            'status' => $providerStatus === 'failed'
                ? 'failed'
                : ($input['method'] === 'mobile_money' ? 'processing' : 'pending'),
            'failure_reason' => $providerStatus === 'failed'
                ? ($provider['message'] ?? $provider['gateway_response'] ?? 'Paystack declined the payment.')
                : null,
        ]);

        return $payment->fresh();
    }

    public function verify(Payment $payment, bool $fulfill = true): Payment
    {
        abort_unless((string) $payment->user_id === (string) auth()->id() || app()->runningInConsole(), 403);
        $provider = $this->paystack->verify($payment->provider_reference ?: $payment->reference);
        return $this->processProviderResult($payment, $provider, $fulfill);
    }

    public function processProviderResult(Payment $payment, array $provider, bool $fulfill = true): Payment
    {
        $providerReference = (string) ($provider['reference'] ?? '');
        $amount = (int) ($provider['amount'] ?? 0);
        $currency = strtoupper((string) ($provider['currency'] ?? ''));
        if (($payment->provider_reference && $providerReference !== (string) $payment->provider_reference) || $amount !== (int) $payment->amount_minor || $currency !== strtoupper($payment->currency)) {
            throw ValidationException::withMessages(['payment' => 'The provider payment does not match the Kulsah payment.']);
        }

        $successful = ($provider['status'] ?? null) === 'success';
        $payment->update([
            'provider_reference' => $providerReference ?: $payment->provider_reference,
            'provider_transaction_id' => isset($provider['id']) ? (string) $provider['id'] : $payment->provider_transaction_id,
            'provider_status' => $provider['status'] ?? null,
            'provider_response' => $provider,
            'status' => $successful ? 'successful' : (($provider['status'] ?? '') === 'failed' ? 'failed' : 'processing'),
            'paid_at' => $successful ? ($payment->paid_at ?: now()) : $payment->paid_at,
            'verified_at' => now(),
            'failure_reason' => $successful ? null : ($provider['gateway_response'] ?? $provider['message'] ?? null),
        ]);

        $result = $successful && $fulfill ? $this->fulfillment->fulfill($payment) : $payment->fresh();
        PaymentStatusChanged::dispatch($result);
        return $result;
    }

    private function resolvePurchase(User $user, string $purpose, array $input): array
    {
        return match ($purpose) {
            'kulcoin' => $this->kulcoinPurchase($input),
            'subscription' => $this->subscriptionPurchase($user, $input),
            'event_ticket' => $this->ticketPurchase($input),
            default => throw ValidationException::withMessages(['purpose' => 'Unsupported payment purpose.']),
        };
    }

    private function kulcoinPurchase(array $input): array
    {
        $package = KulCoinPackage::query()->active()->findOrFail($input['package_id']);
        return [$package, $package->usd_price, $package->currency_code, ['package_id' => $package->id, 'package_code' => $package->code]];
    }

    private function subscriptionPurchase(User $user, array $input): array
    {
        $plan = SubscriptionPlan::query()->where('is_active', true)->findOrFail($input['subscription_plan_id']);
        if ((int) $plan->creator_id === (int) $user->id) {
            throw ValidationException::withMessages(['subscription_plan_id' => 'You cannot subscribe to your own plan.']);
        }
        return [$plan, $plan->price, $plan->currency, ['subscription_plan_id' => $plan->id]];
    }

    private function ticketPurchase(array $input): array
    {
        $event = Event::query()->where('status', 'published')->findOrFail($input['event_id']);
        $type = collect($event->ticket_types ?? [])->firstWhere('code', $input['ticket_type_code']);
        $quantity = (int) ($input['quantity'] ?? 1);
        if (! $type || $quantity < 1 || (int) $event->capacity - (int) $event->tickets_sold < $quantity) {
            throw ValidationException::withMessages(['ticket_type_code' => 'The selected ticket is unavailable.']);
        }
        return [$event, (float) $type['price'] * $quantity, $event->currency, ['event_id' => $event->id, 'ticket_type_code' => $type['code'], 'quantity' => $quantity]];
    }
}
