<?php

namespace App\Jobs;

use App\Services\DeveloperWebhookUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverDeveloperWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(DeveloperWebhookUrl $urls): void
    {
        $delivery = DB::table('developer_webhook_deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }
        $hook = DB::table('developer_webhooks')->where('id', $delivery->webhook_id)->first();
        if (! $hook || ! $hook->enabled) {
            DB::table('developer_webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

            return;
        }
        DB::table('developer_webhook_deliveries')->where('id', $delivery->id)->increment('attempts');
        try {
            $address = $urls->resolve($hook->url);
            $timestamp = (string) time();
            $body = json_encode(json_decode($delivery->payload, true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, Crypt::decryptString($hook->secret));
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$address['host'].':443:'.$address['ip']]]])->withHeaders(['X-Kulsah-Delivery' => (string) $delivery->id, 'X-Kulsah-Event' => $delivery->event, 'X-Kulsah-Signature' => 't='.$timestamp.',v1='.$signature])->withBody($body, 'application/json')->post($hook->url);
            DB::table('developer_webhook_deliveries')->where('id', $delivery->id)->update(['response_status' => $response->status(), 'updated_at' => now()]);
            $response->throw();
            if (! $response->successful()) {
                throw new \RuntimeException('Webhook endpoint returned a redirect.');
            }
            DB::table('developer_webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'error' => null, 'updated_at' => now()]);
        } catch (Throwable $e) {
            DB::table('developer_webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'error' => 'Delivery failed. Check endpoint availability and HTTPS configuration.', 'updated_at' => now()]);
            throw $e;
        }
    }
}
