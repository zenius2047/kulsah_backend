<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    private function client(): PendingRequest
    {
        $secret = (string) config('paystack.secret_key');
        if ($secret === '') {
            throw new RuntimeException('Paystack is not configured.');
        }

        return Http::baseUrl(config('paystack.base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->timeout((int) config('paystack.timeout', 15));
    }

    public function initialize(array $payload, string $method): array
    {
        $endpoint = $method === 'mobile_money' ? '/charge' : '/transaction/initialize';
        $response = $this->client()->post($endpoint, $payload);
        $data = $response->json('data');
        $status = $response->json('status');
        $isSuccessfulResponse = $status === true || $status === 1 || $status === '1' || $status === 'true';

        if (! is_array($data) || empty($data['reference'])) {
            throw new RuntimeException((string) ($response->json('message') ?: 'Paystack initialization failed.'));
        }

        return $data;
    }

    public function verify(string $reference): array
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference));

        if ($response->failed() || $response->json('status') !== true) {
            throw new RuntimeException((string) ($response->json('message') ?: 'Paystack verification failed.'));
        }

        return $response->json('data', []);
    }
}
