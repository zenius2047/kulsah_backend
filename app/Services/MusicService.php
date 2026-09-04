<?php

namespace App\Services;

use App\Contracts\MusicProviderInterface;
use App\Enums\MusicProvider;
use App\Exceptions\MusicProviderException;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MusicService
{
    public function __construct(
        private readonly MusicProviderInterface $provider,
        private readonly MusicReferenceService $musicReferenceService,
    ) {
    }

    public function browse(array $filters, ?User $user = null): array
    {
        $normalized = $this->normalizeFilters($filters);
        $mode = $this->resolveMode($normalized);
        $context = $this->cacheContext($normalized, $user);

        $ttl = $mode === 'search'
            ? (int) data_get(config('music.cache_ttl_seconds'), 'search', 90)
            : (int) data_get(config('music.cache_ttl_seconds'), 'trending', 300);

        $staleTtl = $mode === 'search'
            ? (int) data_get(config('music.stale_ttl_seconds'), 'search', 900)
            : (int) data_get(config('music.stale_ttl_seconds'), 'trending', 1800);

        $payload = $this->rememberWithFallback(
            scope: $mode,
            context: $context,
            ttlSeconds: $ttl,
            staleTtlSeconds: $staleTtl,
            resolver: function () use ($mode, $normalized): array {
                $filters = [
                    'query' => $normalized['search'],
                    'genre' => $normalized['genre'],
                    'mood' => $normalized['mood'],
                    'sort' => $normalized['sort'],
                    'time' => $normalized['time'],
                    'limit' => $normalized['limit'],
                    'offset' => ($normalized['page'] - 1) * $normalized['limit'],
                ];

                return $mode === 'search'
                    ? $this->provider->search($filters)
                    : $this->provider->trending($filters);
            }
        );

        $payload['items'] = $this->decorateTracks($payload['items'] ?? [], $user, $normalized['page'], $normalized['limit']);
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'mode' => $mode,
            'provider' => MusicProvider::Audius->value,
            'pagination' => [
                'current_page' => $normalized['page'],
                'per_page' => $normalized['limit'],
                'has_more_pages' => count($payload['items']) >= $normalized['limit'],
            ],
            'cache' => $payload['cache'] ?? [
                'hit' => false,
                'source' => 'miss',
            ],
        ]);

        return $payload;
    }

    public function show(string $identifier, ?User $user = null): array
    {
        $track = $this->track($identifier, $user);

        return [
            'data' => $track,
            'meta' => [
                'provider' => $track['provider'] ?? MusicProvider::Audius->value,
                'cache' => $track['cache'] ?? [
                    'hit' => false,
                    'source' => 'miss',
                ],
            ],
        ];
    }

    public function track(string $identifier, ?User $user = null): array
    {
        [$provider, $externalId] = $this->parseIdentifier($identifier);
        $context = $this->cacheContext([
            'provider' => $provider,
            'external_id' => $externalId,
        ], $user);
        $ttl = (int) data_get(config('music.cache_ttl_seconds'), 'track', 600);
        $staleTtl = (int) data_get(config('music.stale_ttl_seconds'), 'track', 3600);

        $payload = $this->rememberWithFallback(
            scope: 'track',
            context: $context,
            ttlSeconds: $ttl,
            staleTtlSeconds: $staleTtl,
            resolver: function () use ($externalId): array {
                $track = $this->provider->track($externalId);

                if (! is_array($track) || $track === []) {
                    throw ValidationException::withMessages([
                        'music' => 'The requested music track is unavailable.',
                    ]);
                }

                return [
                    'items' => [$track],
                    'meta' => [
                        'provider' => $track['provider'] ?? MusicProvider::Audius->value,
                    ],
                ];
            }
        );

        $track = $payload['items'][0] ?? null;

        if (! is_array($track) || ($track['streamable'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'music' => 'This music track is not available for preview.',
            ]);
        }

        $track = $this->decorateTrack($track, $user);
        $track['cache'] = $payload['cache'] ?? ['hit' => false, 'source' => 'miss'];

        return $track;
    }

    /**
     * @return Response|RedirectResponse|StreamedResponse
     */
    public function stream(string $identifier, ?User $user = null): Response|RedirectResponse|StreamedResponse
    {
        $track = $this->track($identifier, $user);
        $externalId = (string) $track['external_id'];
        $response = $this->provider->stream($externalId);

        if (in_array($response->status(), [301, 302, 307, 308], true) && is_string($response->header('Location')) && $response->header('Location') !== '') {
            return redirect()->away($response->header('Location'));
        }

        if (! $response->successful()) {
            throw new MusicProviderException(
                'Audius stream resolution failed.',
                $response->status(),
                $this->retryAfter($response),
                ['identifier' => $identifier]
            );
        }

        $headers = array_filter([
            'Content-Type' => $response->header('Content-Type', 'audio/mpeg'),
            'Content-Length' => $response->header('Content-Length'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Accept-Ranges' => $response->header('Accept-Ranges'),
        ], static fn ($value) => $value !== null && $value !== '');

        return response()->stream(function () use ($response): void {
            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                echo $body->read(8192);

                if (connection_aborted()) {
                    break;
                }
            }
        }, $response->status(), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function decorateTrack(array $track, ?User $user = null): array
    {
        $track['id'] = $this->normalizeIdentifier(
            (string) ($track['provider'] ?? MusicProvider::Audius->value),
            (string) ($track['external_id'] ?? '')
        );
        $track['provider'] = (string) ($track['provider'] ?? MusicProvider::Audius->value);
        $track['stream_endpoint'] = $track['stream_endpoint'] ?? $this->streamEndpoint($track['id']);
        $track['stream_url'] = $track['stream_url'] ?? $track['stream_endpoint'];
        $track['usage_count'] = $this->musicReferenceService->usageCountForIdentifier((string) $track['id']);
        $track['is_saved'] = false;
        $track['current_user'] = [
            'is_authenticated' => $user !== null,
        ];

        return $track;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array<string, mixed>>
     */
    private function decorateTracks(array $tracks, ?User $user, int $page, int $limit): array
    {
        $tracks = array_values(array_filter(array_map(function (array $track) use ($user): ?array {
            if (($track['streamable'] ?? false) !== true) {
                return null;
            }

            return $this->decorateTrack($track, $user);
        }, $tracks)));

        $usageCounts = $this->musicReferenceService->usageCountsFor($tracks);

        foreach ($tracks as $index => $track) {
            $normalizedId = (string) ($track['id'] ?? '');
            $tracks[$index]['usage_count'] = $usageCounts[$normalizedId] ?? (int) ($track['usage_count'] ?? 0);
        }

        return $tracks;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw ValidationException::withMessages([
                'music' => 'A music identifier is required.',
            ]);
        }

        if (str_contains($identifier, ':')) {
            [$provider, $externalId] = array_pad(explode(':', $identifier, 2), 2, '');
        } else {
            $provider = (string) config('music.default_provider', MusicProvider::Audius->value);
            $externalId = $identifier;
        }

        $provider = strtolower(trim($provider));
        $externalId = trim($externalId);

        if ($provider === '' || $externalId === '') {
            throw ValidationException::withMessages([
                'music' => 'The music identifier format is invalid.',
            ]);
        }

        return [$provider, $externalId];
    }

    private function normalizeIdentifier(string $provider, string $externalId): string
    {
        return strtolower(trim($provider)).':'.trim($externalId);
    }

    private function streamEndpoint(string $normalizedId): string
    {
        return route('music.stream', ['musicTrack' => $normalizedId], true);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $resolver
     * @return T
     */
    private function rememberWithFallback(string $scope, array $context, int $ttlSeconds, int $staleTtlSeconds, \Closure $resolver): mixed
    {
        $cache = $this->cacheStore();
        $cacheKey = $this->cacheKey($scope, $context);
        $staleKey = $cacheKey.':stale';
        $breakerKey = $cacheKey.':breaker';

        if ($cache->has($cacheKey)) {
            return [
                ...($cache->get($cacheKey) ?: []),
                'cache' => [
                    'hit' => true,
                    'source' => 'fresh',
                ],
            ];
        }

        if ($cache->has($breakerKey) && $cache->has($staleKey)) {
            $stale = $cache->get($staleKey) ?: [];
            $stale['cache'] = [
                'hit' => true,
                'source' => 'stale_breaker',
            ];

            return $stale;
        }

        try {
            $payload = $resolver();
            $payload['cache'] = [
                'hit' => false,
                'source' => 'miss',
            ];

            $cache->put($cacheKey, $payload, now()->addSeconds($ttlSeconds));
            $cache->put($staleKey, $payload, now()->addSeconds($staleTtlSeconds));

            return $payload;
        } catch (MusicProviderException $exception) {
            $breakerTtl = max(15, (int) ($exception->retryAfterSeconds() ?? 30));
            $cache->put($breakerKey, [
                'status' => $exception->statusCode(),
                'message' => $exception->getMessage(),
            ], now()->addSeconds($breakerTtl));

            if ($cache->has($staleKey)) {
                $stale = $cache->get($staleKey) ?: [];
                $stale['cache'] = [
                    'hit' => true,
                    'source' => 'stale_fallback',
                ];

                Log::warning('Audius failure served from stale cache.', [
                    'scope' => $scope,
                    'status' => $exception->statusCode(),
                    'context' => $context,
                ]);

                return $stale;
            }

            Log::error('Audius failure with no cached fallback available.', [
                'scope' => $scope,
                'status' => $exception->statusCode(),
                'context' => $context,
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Unexpected music provider error.', [
                'scope' => $scope,
                'context' => $context,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function retryAfter(HttpResponse $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        return is_numeric($retryAfter) ? (int) $retryAfter : null;
    }

    private function resolveMode(array $filters): string
    {
        if ($filters['search'] !== null && $filters['search'] !== '') {
            return 'search';
        }

        if (($filters['mood'] ?? []) !== [] || ($filters['sort'] ?? null) !== null) {
            return 'search';
        }

        return ($filters['trending'] ?? true) ? 'trending' : 'trending';
    }

    private function normalizeFilters(array $filters): array
    {
        $search = $this->collapseWhitespace($filters['search'] ?? null);

        if (is_string($search)) {
            $search = trim($search);
        }

        return [
            'search' => $search !== '' ? $search : null,
            'genre' => $this->normalizeArrayFilter($filters['genre'] ?? null),
            'mood' => $this->normalizeArrayFilter($filters['mood'] ?? null),
            'trending' => $this->toBool($filters['trending'] ?? null, true),
            'time' => $this->normalizeTime($filters['time'] ?? null),
            'sort' => $this->normalizeSort($filters['sort'] ?? null),
            'page' => max(1, is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1),
            'limit' => max(1, min((int) config('music.max_limit', 50), is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : (int) config('music.default_limit', 20))),
        ];
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeArrayFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : '',
            $value
        ))));
    }

    private function normalizeSort(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : null;

        return in_array($value, ['relevant', 'popular', 'recent'], true) ? $value : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : null;

        return match ($value) {
            'week', 'month', 'year' => $value,
            'all_time', 'alltime' => 'allTime',
            default => null,
        };
    }

    private function collapseWhitespace(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function cacheStore(): CacheRepository
    {
        return Cache::store(config('cache.default'));
    }

    private function cacheKey(string $scope, array $context): string
    {
        return 'music:'.$scope.':'.substr(hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
    }

    private function cacheContext(array $filters, ?User $user): array
    {
        return array_merge($filters, [
            'user_id' => $user?->id,
        ]);
    }
}
