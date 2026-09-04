<?php

namespace App\Services;

use App\Contracts\MusicProviderInterface;
use App\Enums\MusicProvider;
use App\Exceptions\MusicProviderException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AudiusMusicProvider implements MusicProviderInterface
{
    public function trending(array $filters = []): array
    {
        return $this->fetchTrackCollection('trending', '/tracks/trending', [
            'genre' => $this->scalarOrNull($filters['genre'] ?? null),
            'time' => $this->normalizeTimeWindow($filters['time'] ?? null),
            'limit' => $this->limit($filters['limit'] ?? null),
            'offset' => $this->offset($filters['offset'] ?? null),
        ]);
    }

    public function search(array $filters = []): array
    {
        $query = $this->stringOrNull($filters['query'] ?? null);
        $payload = [
            'query' => $query ?? '',
            'genre' => $this->normalizeList($filters['genre'] ?? null),
            'mood' => $this->normalizeList($filters['mood'] ?? null),
            'sortMethod' => $this->normalizeSort($filters['sort'] ?? null),
            'limit' => $this->limit($filters['limit'] ?? null),
            'offset' => $this->offset($filters['offset'] ?? null),
        ];

        return $this->fetchTrackCollection('search', '/tracks/search', $payload);
    }

    public function track(string $externalId, ?string $viewerToken = null): ?array
    {
        try {
            $response = $this->request('track', 'GET', "/tracks/{$this->sanitizeId($externalId)}");
        } catch (MusicProviderException $exception) {
            if ($exception->statusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $track = $this->extractSingleTrack($response);

        return $track ? $this->normalizeTrack($track) : null;
    }

    public function stream(string $externalId, ?string $viewerToken = null): HttpResponse
    {
        return $this->request(
            'stream',
            'GET',
            "/tracks/{$this->sanitizeId($externalId)}/stream",
            [],
            [
                'allow_redirects' => false,
                'stream' => true,
            ]
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function fetchTrackCollection(string $operation, string $endpoint, array $query): array
    {
        $response = $this->request($operation, 'GET', $endpoint, $query);
        $payload = $response->json();
        $tracks = $this->extractTrackList($payload);

        return [
            'items' => array_values(array_filter(array_map(
                fn (array $track): array => $this->normalizeTrack($track),
                $tracks
            ))),
            'meta' => [
                'provider' => MusicProvider::Audius->value,
                'source' => $operation,
                'query' => $query,
                'count' => count($tracks),
                'status' => $response->status(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $httpOptions
     */
    private function request(string $operation, string $method, string $endpoint, array $query = [], array $httpOptions = []): HttpResponse
    {
        $startedAt = microtime(true);
        $retryCount = max(1, (int) config('services.audius.retries', 2));
        $response = Http::baseUrl((string) config('services.audius.base_url', 'https://api.audius.co/v1'))
            ->acceptJson()
            ->timeout((float) config('services.audius.timeout', 8))
            ->connectTimeout((float) config('services.audius.connect_timeout', 3))
            ->retry($retryCount, 250, function (mixed $exception, mixed $request = null): bool {
                return $exception instanceof Throwable && $this->shouldRetry($exception);
            })
            ->withHeaders($this->headers())
            ->withOptions($httpOptions)
            ->send($method, ltrim($endpoint, '/'), ['query' => $query]);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->successful() || in_array($response->status(), [301, 302, 307, 308], true)) {
            Log::debug('Audius request completed.', [
                'operation' => $operation,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'retry_count' => $retryCount,
            ]);

            return $response;
        }

        $retryAfter = $this->retryAfterSeconds($response);

        Log::warning('Audius request failed.', [
            'operation' => $operation,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'retry_count' => $retryCount,
            'retry_after_seconds' => $retryAfter,
        ]);

        throw new MusicProviderException(
            message: $this->failureMessage($operation, $response->status()),
            statusCode: $response->status() >= 400 ? $response->status() : 502,
            retryAfterSeconds: $retryAfter,
            context: [
                'operation' => $operation,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function extractTrackList(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (! is_array($payload)) {
            return [];
        }

        foreach (['data', 'tracks', 'results'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function extractSingleTrack(HttpResponse $response): ?array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        if (array_is_list($payload)) {
            return $payload[0] ?? null;
        }

        foreach (['data', 'track', 'result'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $candidate = $payload[$key];

                if (array_is_list($candidate)) {
                    return $candidate[0] ?? null;
                }

                return $candidate;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function normalizeTrack(array $track): array
    {
        $user = is_array($track['user'] ?? null) ? $track['user'] : [];
        $artwork = is_array($track['artwork'] ?? null) ? $track['artwork'] : [];
        $trackId = $this->stringOrNull($track['id'] ?? $track['track_id'] ?? null);
        $provider = MusicProvider::Audius->value;
        $normalizedId = $trackId ? $provider.':'.$trackId : null;
        $thumbnail = $this->pickFirstString($artwork, ['150x150', '100x100', 'thumbnail', 'small', 'medium']);
        $largeArtwork = $this->pickFirstString($artwork, ['1000x1000', '480x480', 'large', 'full']);
        $smallArtwork = $thumbnail ?: $largeArtwork;
        $playCount = $this->intOrNull($track['play_count'] ?? $track['playCount'] ?? null);
        $favoriteCount = $this->intOrNull($track['favorite_count'] ?? $track['favorites_count'] ?? null);
        $repostCount = $this->intOrNull($track['repost_count'] ?? $track['reposts_count'] ?? null);

        return array_filter([
            'id' => $normalizedId,
            'provider' => $provider,
            'external_id' => $trackId,
            'title' => $this->stringOrNull($track['title'] ?? $track['name'] ?? null),
            'artist' => $this->stringOrNull($user['name'] ?? $user['handle'] ?? $user['username'] ?? null),
            'artist_id' => $this->stringOrNull($user['id'] ?? $user['user_id'] ?? null),
            'artist_username' => $this->stringOrNull($user['handle'] ?? $user['username'] ?? null),
            'artist_verified' => (bool) ($user['is_verified'] ?? $user['verified'] ?? false),
            'artwork' => array_filter([
                'thumbnail' => $smallArtwork,
                'small' => $smallArtwork,
                'medium' => $thumbnail,
                'large' => $largeArtwork ?: $thumbnail,
                'original' => $this->pickFirstString($artwork, ['original', 'full', '1000x1000', '480x480']) ?: $largeArtwork,
            ]),
            'thumbnail_artwork' => $smallArtwork,
            'large_artwork' => $largeArtwork ?: $thumbnail,
            'duration' => $this->intOrNull($track['duration'] ?? $track['duration_ms'] ?? null),
            'genre' => $this->stringOrNull($track['genre'] ?? null),
            'mood' => $this->stringOrNull($track['mood'] ?? null),
            'tags' => $this->normalizeList($track['tags'] ?? null),
            'release_date' => $this->stringOrNull($track['release_date'] ?? $track['created_at'] ?? null),
            'play_count' => $playCount ?? 0,
            'favorite_count' => $favoriteCount ?? 0,
            'repost_count' => $repostCount ?? 0,
            'streamable' => (bool) ($track['is_streamable'] ?? $track['streamable'] ?? $track['available'] ?? true),
            'downloadable' => (bool) ($track['downloadable'] ?? $track['is_downloadable'] ?? false),
            'permalink' => $this->stringOrNull($track['permalink'] ?? $track['url'] ?? null),
            'stream_endpoint' => null,
            'stream_url' => null,
            'source_attribution' => [
                'provider' => $provider,
                'provider_name' => 'Audius',
                'artist' => $this->stringOrNull($user['name'] ?? $user['handle'] ?? $user['username'] ?? null),
                'artist_username' => $this->stringOrNull($user['handle'] ?? $user['username'] ?? null),
                'permalink' => $this->stringOrNull($track['permalink'] ?? null),
            ],
            'license' => $this->stringOrNull($track['license'] ?? null),
            'rights_status' => $this->stringOrNull($track['rights_status'] ?? null),
            'usage_count' => 0,
            'is_saved' => false,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        $apiKey = $this->stringOrNull(config('services.audius.api_key'));
        $bearerToken = $this->stringOrNull(config('services.audius.bearer_token'));

        if ($apiKey !== null) {
            $headers['X-API-Key'] = $apiKey;
        }

        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer '.$bearerToken;
        }

        return $headers;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if (! method_exists($exception, 'response')) {
            return false;
        }

        $response = $exception->response;

        if (! $response) {
            return true;
        }

        $status = $response->status();

        return $status === 429 || $status >= 500;
    }

    private function failureMessage(string $operation, int $status): string
    {
        if ($status === 404) {
            return "Audius {$operation} resource was not found.";
        }

        if ($status === 429) {
            return "Audius rate limited the {$operation} request.";
        }

        return "Audius {$operation} request failed.";
    }

    private function retryAfterSeconds(HttpResponse $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        return is_numeric($retryAfter) ? (int) $retryAfter : null;
    }

    private function limit(mixed $value): int
    {
        return max(1, min((int) config('music.max_limit', 50), is_numeric($value) ? (int) $value : (int) config('music.default_limit', 20)));
    }

    private function offset(mixed $value): int
    {
        return max(0, is_numeric($value) ? (int) $value : 0);
    }

    /**
     * @return array<int, string>|string|null
     */
    private function normalizeList(mixed $value): array|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $items = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter(array_map(
                fn ($item) => is_string($item) ? trim($item) : '',
                $items
            ))));
        }

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : '',
            $value
        ))));
    }

    private function normalizeSort(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $value = Str::lower(str_replace(['-', ' '], '_', $value));

        return in_array($value, ['relevant', 'popular', 'recent'], true) ? $value : null;
    }

    private function normalizeTimeWindow(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $value = Str::lower(str_replace(['-', ' '], '_', $value));

        return match ($value) {
            'all_time', 'alltime' => 'allTime',
            'week', 'month', 'year' => $value,
            default => null,
        };
    }

    private function scalarOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->stringOrNull($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function sanitizeId(string $externalId): string
    {
        return rawurlencode(trim($externalId));
    }

    private function pickFirstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    if (preg_match('~^\[[^\]]+\]\((https?://[^)]+)\)$~', $value, $matches) === 1) {
                        return $matches[1];
                    }

                    return $value;
                }
            }
        }

        return null;
    }
}
