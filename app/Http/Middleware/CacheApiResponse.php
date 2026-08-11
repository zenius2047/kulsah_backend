<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CacheApiResponse
{
    public function handle(Request $request, Closure $next, ?string $ttlSeconds = null): Response
    {
        if (! config('api-cache.enabled', true) || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $cache = $this->cacheStore();
        $key = $this->cacheKey($request);

        try {
            $cached = $cache->get($key);
        } catch (Throwable $throwable) {
            report($throwable);

            return $next($request);
        }

        if (is_array($cached) && array_key_exists('body', $cached)) {
            return response()
                ->json($cached['body'], (int) ($cached['status'] ?? 200))
                ->header('X-Cache', 'HIT');
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }

        $ttl = max(1, (int) ($ttlSeconds ?: config('api-cache.ttl_seconds', 60)));

        try {
            $cache->put($key, [
                'status' => $response->getStatusCode(),
                'body' => $response->getData(true),
            ], now()->addSeconds($ttl));
        } catch (Throwable $throwable) {
            report($throwable);
        }

        $response->headers->set('X-Cache', 'MISS');

        return $response;
    }

    private function cacheStore(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    private function cacheKey(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        $context = [
            'viewer_id' => (int) $request->user()?->id,
            'path' => '/'.$request->path(),
            'query' => $query,
            'accept' => $request->header('Accept'),
        ];

        return sprintf(
            '%s:%s',
            trim((string) config('api-cache.prefix', 'api-response'), ':'),
            hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES) ?: '')
        );
    }
}
