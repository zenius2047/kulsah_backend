<?php

namespace Tests\Unit;

use App\Services\FastApiRecommendationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FastApiRecommendationServiceTest extends TestCase
{
    public function test_record_event_sends_payload_to_fastapi(): void
    {
        config()->set('services.fastapi.enabled', true);
        config()->set('services.fastapi.url', 'http://127.0.0.1:8001');
        config()->set('services.fastapi.shared_secret', 'test-secret');

        Http::fake([
            'http://127.0.0.1:8001/events' => Http::response([
                'message' => 'ok',
            ], 200),
        ]);

        $service = app(FastApiRecommendationService::class);
        $service->recordEvent(
            userId: 42,
            eventType: 'watch',
            videoId: 99,
            value: 1.0,
            terms: ['music']
        );

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Kulsah-Timestamp')[0] ?? null;
            $signature = $request->header('X-Kulsah-Signature')[0] ?? null;
            $serviceName = $request->header('X-Kulsah-Service')[0] ?? null;

            if (! $timestamp || ! $signature || $serviceName !== 'laravel') {
                return false;
            }

            $body = $request->body();
            $expected = hash_hmac(
                'sha256',
                implode("\n", [
                    $timestamp,
                    'POST',
                    '/events',
                    $body,
                ]),
                'test-secret'
            );

            return hash_equals($expected, $signature);
        });
    }
}
