<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request)
    {
        $signature = (string) $request->header('x-paystack-signature');
        $expected = hash_hmac('sha512', $request->getContent(), (string) config('paystack.secret_key'));
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::warning('Rejected Paystack webhook with invalid signature.');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        if (($payload['event'] ?? null) !== 'charge.success') {
            return response()->json(['received' => true]);
        }

        $provider = $payload['data'] ?? [];
        $reference = $provider['reference'] ?? null;
        $payment = $reference
            ? Payment::query()->where('provider_reference', $reference)->orWhere('reference', $reference)->first()
            : null;
        if (! $payment) {
            Log::warning('Paystack webhook payment not found.', ['reference' => $reference]);
            return response()->json(['received' => true]);
        }

        $this->payments->processProviderResult($payment, $provider);
        return response()->json(['received' => true]);
    }
}
