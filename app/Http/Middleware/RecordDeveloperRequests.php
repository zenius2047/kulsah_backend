<?php

namespace App\Http\Middleware;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordDeveloperRequests
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('developer_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $r, Response $response): void
    {
        if (! $r->is('api/v1/developer/*', 'api/v1/auth/login', 'api/v1/auth/activate', 'api/v1/auth/logout', 'oauth/token', 'oauth/authorize')) {
            return;
        }
        if (! Schema::hasTable('developer_request_logs')) {
            return;
        }
        try {
            $actor = $r->user();
            $owner = $r->attributes->get('workspace_owner_id', $actor?->id);
            $client = $r->route('client');
            $clientId = is_object($client) ? $client->id : ($client ?: $r->input('client_id'));
            if (! $clientId && $response->isSuccessful() && $r->isMethod('POST') && $r->path() === 'api/v1/developer/apps') {
                $payload = json_decode($response->getContent(), true);
                $clientId = $payload['data']['id'] ?? null;
            }
            if ($clientId) {
                $c = DB::table('oauth_clients')->where('id', $clientId)->where('owner_type', User::class)->first();
                if ($c) {
                    $owner = $c->owner_id;
                } else {
                    $clientId = null;
                }
            }
            if (! $owner) {
                return;
            }
            $event = null;
            $path = $r->path();
            if ($response->isSuccessful()) {
                if ($r->isMethod('POST') && $path === 'api/v1/developer/apps') {
                    $event = 'application.created';
                } elseif ($r->isMethod('PATCH') && $r->is('api/v1/developer/apps/*')) {
                    $event = 'application.updated';
                } elseif ($r->isMethod('DELETE') && $r->is('api/v1/developer/apps/*')) {
                    $event = 'application.deleted';
                } elseif ($r->isMethod('POST') && $r->is('api/v1/developer/apps/*/regenerate-secret')) {
                    $event = 'application.secret_rotated';
                } elseif ($r->isMethod('DELETE') && $r->is('api/v1/developer/tokens/*')) {
                    $event = 'token.revoked';
                } elseif ($path === 'api/v1/auth/login') {
                    $event = 'login.success';
                }
            }
            $id = DB::table('developer_request_logs')->insertGetId(['owner_id' => $owner, 'actor_id' => $actor?->id, 'client_id' => $clientId, 'method' => $r->method(), 'path' => '/'.Str::limit($path, 490, ''), 'status' => $response->getStatusCode(), 'duration_ms' => max(0, (int) ((microtime(true) - $r->attributes->get('developer_started_at', microtime(true))) * 1000)), 'ip' => $r->ip(), 'country_code' => $actor?->country_code, 'event' => $event, 'created_at' => now()]);
            if ($event && str_starts_with($event, 'application.')) {
                $preferences = DB::table('developer_preferences')->where('user_id', $owner)->first();
                $notify = $event === 'application.secret_rotated' ? ($preferences->security_alerts ?? true) : ($preferences->product_notifications ?? true);
                if ($notify) {
                    DB::table('developer_notifications')->insert(['user_id' => $owner, 'title' => Str::headline($event), 'message' => 'A workspace action completed: '.$r->method().' /'.$path, 'created_at' => now()]);
                }
            }
            if ($event && in_array($event, config('developer.webhook_events', []))) {
                foreach (DB::table('developer_webhooks')->where('owner_id', $owner)->where('enabled', true)->get() as $hook) {
                    if (! in_array($event, json_decode($hook->events, true))) {
                        continue;
                    }
                    $delivery = DB::table('developer_webhook_deliveries')->insertGetId(['webhook_id' => $hook->id, 'event' => $event, 'payload' => json_encode(['id' => $id, 'event' => $event, 'client_id' => $clientId, 'actor_id' => $actor?->id, 'timestamp' => now()->toIso8601String()]), 'created_at' => now(), 'updated_at' => now()]);
                    DeliverDeveloperWebhook::dispatch($delivery);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
