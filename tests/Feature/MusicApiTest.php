<?php

namespace Tests\Feature;

use App\Services\MusicReferenceService;
use App\Services\MusicService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class MusicApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('services.audius.base_url', 'https://audius.test/v1');
        config()->set('services.audius.retries', 0);
        config()->set('music.max_limit', 10);
        config()->set('music.default_limit', 5);

        Cache::flush();
        Http::preventStrayRequests();

        app()->instance(MusicReferenceService::class, new class extends MusicReferenceService
        {
            public function recordUsage(array $track, int $userId): ?\App\Models\MusicTrackReference
            {
                return null;
            }

            public function usageCountsFor(array $tracks): array
            {
                $counts = [];

                foreach ($tracks as $track) {
                    if (isset($track['id'])) {
                        $counts[$track['id']] = 7;
                    }
                }

                return $counts;
            }

            public function usageCountForIdentifier(string $identifier): int
            {
                return 7;
            }
        });
    }

    public function test_trending_music_is_normalized_and_filters_non_streamable_tracks(): void
    {
        $requestCount = 0;

        Http::fake(function (HttpRequest $request) use (&$requestCount) {
            $requestCount++;

            if (str_contains($request->url(), '/tracks/trending')) {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'abc123',
                            'title' => 'Sunrise',
                            'user' => [
                                'id' => 'artist-1',
                                'name' => 'DJ Ada',
                                'handle' => 'ada',
                                'is_verified' => true,
                            ],
                            'artwork' => [
                                '150x150' => 'https://cdn.example.com/thumb.jpg',
                                '1000x1000' => 'https://cdn.example.com/large.jpg',
                            ],
                            'duration' => 180,
                            'genre' => 'Afrobeats',
                            'mood' => 'uplifting',
                            'tags' => ['afrobeats', 'summer'],
                            'release_date' => '2026-09-01',
                            'play_count' => 1234,
                            'favorite_count' => 12,
                            'repost_count' => 3,
                            'is_streamable' => true,
                            'downloadable' => false,
                            'permalink' => 'https://audius.co/tracks/abc123',
                        ],
                        [
                            'id' => 'def456',
                            'title' => 'Hidden',
                            'user' => ['name' => 'Ghost'],
                            'is_streamable' => false,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->getJson('/api/v1/creator/music?limit=10');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'audius:abc123')
            ->assertJsonPath('data.0.provider', 'audius')
            ->assertJsonPath('data.0.title', 'Sunrise')
            ->assertJsonPath('data.0.artist', 'DJ Ada')
            ->assertJsonPath('data.0.artist_verified', true)
            ->assertJsonPath('data.0.stream_url', rtrim((string) config('app.url'), '/') . '/api/v1/creator/music/audius:abc123/stream')
            ->assertJsonPath('data.0.usage_count', 7)
            ->assertJsonPath('meta.mode', 'trending');

        $this->assertSame(1, $requestCount);
    }

    public function test_music_search_uses_the_audius_search_endpoint(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertStringContainsString('/tracks/search', $request->url());

            return Http::response([
                'data' => [
                    [
                        'id' => 'search-1',
                        'title' => 'Afrobeats Anthem',
                        'user' => [
                            'name' => 'Creator One',
                            'handle' => 'creatorone',
                        ],
                        'is_streamable' => true,
                    ],
                ],
            ], 200);
        });

        $response = $this->getJson('/api/v1/creator/music?search=Afrobeats&genre=Afrobeats&mood=happy&sort=relevant&limit=5');

        $response->assertOk()
            ->assertJsonPath('meta.mode', 'search')
            ->assertJsonPath('data.0.id', 'audius:search-1')
            ->assertJsonPath('data.0.title', 'Afrobeats Anthem');
    }

    public function test_music_details_are_normalized_and_include_usage_count(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertStringContainsString('/tracks/abc123', $request->url());

            return Http::response([
                'data' => [
                    'id' => 'abc123',
                    'title' => 'Sunrise',
                    'user' => [
                        'id' => 'artist-1',
                        'name' => 'DJ Ada',
                        'handle' => 'ada',
                        'verified' => true,
                    ],
                    'artwork' => [
                        '150x150' => 'https://cdn.example.com/thumb.jpg',
                    ],
                    'duration' => 180,
                    'genre' => 'Afrobeats',
                    'is_streamable' => true,
                    'permalink' => 'https://audius.co/tracks/abc123',
                ],
            ], 200);
        });

        $response = $this->getJson('/api/v1/creator/music/audius:abc123');

        $response->assertOk()
            ->assertJsonPath('data.id', 'audius:abc123')
            ->assertJsonPath('data.provider', 'audius')
            ->assertJsonPath('data.title', 'Sunrise')
            ->assertJsonPath('data.usage_count', 7)
            ->assertJsonPath('data.stream_url', rtrim((string) config('app.url'), '/') . '/api/v1/creator/music/audius:abc123/stream');
    }

    public function test_music_stream_redirects_to_audius_when_available(): void
    {
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/tracks/abc123/stream')) {
                return Http::response('', 302, [
                    'Location' => 'https://cdn.audius.test/abc123.mp3',
                ]);
            }

            if (str_contains($request->url(), '/tracks/abc123')) {
                return Http::response([
                    'data' => [
                        'id' => 'abc123',
                        'title' => 'Sunrise',
                        'user' => ['name' => 'DJ Ada', 'handle' => 'ada'],
                        'is_streamable' => true,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->get('/api/v1/creator/music/audius:abc123/stream');

        $response->assertRedirect('https://cdn.audius.test/abc123.mp3');
    }

    public function test_music_requests_reject_invalid_query_parameters(): void
    {
        $response = $this->getJson('/api/v1/creator/music?limit=500&sort=wrong');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['limit', 'sort']);
    }

    public function test_music_results_fall_back_to_stale_cache_when_audius_fails(): void
    {
        $requestCount = 0;

        Http::fake(function (HttpRequest $request) use (&$requestCount) {
            $requestCount++;

            if ($requestCount === 1) {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'abc123',
                            'title' => 'Sunrise',
                            'user' => ['name' => 'DJ Ada', 'handle' => 'ada'],
                            'is_streamable' => true,
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'message' => 'Upstream failure',
            ], 500);
        });

        $service = app(MusicService::class);
        $cacheKey = $this->musicCacheKey($service, 'trending', [
            'search' => null,
            'genre' => [],
            'mood' => [],
            'trending' => true,
            'time' => null,
            'sort' => null,
            'page' => 1,
            'limit' => 5,
            'user_id' => null,
        ]);

        $first = $this->getJson('/api/v1/creator/music?limit=5');
        $first->assertOk()->assertJsonPath('data.0.title', 'Sunrise');

        Cache::store('array')->forget($cacheKey);

        $second = $this->getJson('/api/v1/creator/music?limit=5');
        $second->assertOk()
            ->assertJsonPath('data.0.title', 'Sunrise')
            ->assertJsonPath('meta.cache.source', 'stale_fallback');

        $this->assertSame(2, $requestCount);
    }

    public function test_non_streamable_tracks_are_rejected_from_track_details(): void
    {
        Http::fake(function (HttpRequest $request) {
            return Http::response([
                'data' => [
                    'id' => 'abc123',
                    'title' => 'Hidden',
                    'user' => ['name' => 'Ghost'],
                    'is_streamable' => false,
                ],
            ], 200);
        });

        $response = $this->getJson('/api/v1/creator/music/audius:abc123');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['music']);
    }

    private function musicCacheKey(MusicService $service, string $scope, array $context): string
    {
        $method = new ReflectionMethod($service, 'cacheKey');
        $method->setAccessible(true);

        return $method->invoke($service, $scope, $context);
    }
}
