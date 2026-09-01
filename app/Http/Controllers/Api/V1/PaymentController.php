<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function initialize(Request $request)
    {
        abort_if(! $request->user()->email, 422, 'A customer email is required to initialize payment.');
        $input = $request->validate([
            'purpose' => ['required', Rule::in(['kulcoin', 'subscription', 'event_ticket'])],
            'method' => ['required', Rule::in(['card', 'mobile_money'])],
            'package_id' => ['required_if:purpose,kulcoin', 'integer', 'exists:kulcoin_packages,id'],
            'subscription_plan_id' => ['required_if:purpose,subscription', 'integer', 'exists:subscription_plans,id'],
            'event_id' => ['required_if:purpose,event_ticket', 'integer', 'exists:events,id'],
            'ticket_type_code' => ['required_if:purpose,event_ticket', 'string', 'max:80'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
            'provider' => ['required_if:method,mobile_money', Rule::in(config('paystack.mobile_money_providers', []))],
            'phone' => ['required_if:method,mobile_money', 'string', 'regex:/^\+?[0-9]{9,15}$/'],
        ]);

        $payment = $this->payments->initialize($request->user(), $input);
        return response()->json(['data' => $this->serialize($payment)], 201);
    }

    public function verify(Request $request, Payment $payment)
    {
        abort_unless((int) $payment->user_id === (int) $request->user()->id, 403);
        return response()->json(['data' => $this->serialize($this->payments->verify($payment))]);
    }

    public function show(Request $request, Payment $payment)
    {
        abort_unless((int) $payment->user_id === (int) $request->user()->id, 403);
        return response()->json(['data' => $this->serialize($payment)]);
    }

    private function serialize(Payment $payment): array
    {
        return [
            'id' => $payment->id, 'reference' => $payment->reference, 'purpose' => $payment->purpose,
            'amount' => $payment->amount_minor / 100, 'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency, 'status' => $payment->status,
            'provider_status' => $payment->provider_status, 'channel' => $payment->channel,
            'authorization_url' => $payment->provider_response['authorization_url'] ?? null,
            'access_code' => $payment->provider_response['access_code'] ?? null,
            'paid_at' => $payment->paid_at?->toIso8601String(), 'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
        ];
    }
}
