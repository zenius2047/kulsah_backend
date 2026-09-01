<?php

namespace Tests\Feature;

use App\Models\Onboarding;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoLike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FastApiRecommendationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_uses_fastapi_rankings_when_the_service_is_available(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', true);
        config()->set('services.fastapi.url', 'http://127.0.0.1:8001');
        config()->set('services.fastapi.shared_secret', 'test-secret');
        Cache::flush();

        $viewer = User::factory()->create([
            'username' => 'ai_viewer',
        ]);
        $firstCreator = User::factory()->create([
            'username' => 'ai_creator_1',
        ]);
        $secondCreator = User::factory()->create([
            'username' => 'ai_creator_2',
        ]);
        $thirdCreator = User::factory()->create([
            'username' => 'ai_creator_3',
        ]);

        $firstVideo = Video::create([
            'user_id' => $firstCreator->id,
            'title' => 'First clip',
            'caption' => 'First caption',
            'visibility' => 'public',
            'source_url' => 'https://example.com/1.mp4',
            'source_key' => 'videos/originals/1/1.mp4',
            'cdn_url' => 'https://cdn.example.com/1.mp4',
            'thumbnail_url' => 'https://cdn.example.com/1.jpg',
            'duration' => 20,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/1.m3u8',
            'metadata' => [],
        ]);

        $secondVideo = Video::create([
            'user_id' => $secondCreator->id,
            'title' => 'Second clip',
            'caption' => 'Second caption',
            'visibility' => 'public',
            'source_url' => 'https://example.com/2.mp4',
            'source_key' => 'videos/originals/1/2.mp4',
            'cdn_url' => 'https://cdn.example.com/2.mp4',
            'thumbnail_url' => 'https://cdn.example.com/2.jpg',
            'duration' => 25,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/2.m3u8',
            'metadata' => [],
        ]);

        $thirdVideo = Video::create([
            'user_id' => $thirdCreator->id,
            'title' => 'Third clip',
            'caption' => 'Third caption',
            'visibility' => 'public',
            'source_url' => 'https://example.com/3.mp4',
            'source_key' => 'videos/originals/1/3.mp4',
            'cdn_url' => 'https://cdn.example.com/3.mp4',
            'thumbnail_url' => 'https://cdn.example.com/3.jpg',
            'duration' => 30,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/3.m3u8',
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:8001/recommend' => Http::response([
                'user_id' => $viewer->id,
                'videos' => [
                    ['video_id' => $thirdVideo->id, 'score' => 0.99],
                    ['video_id' => $firstVideo->id, 'score' => 0.88],
                    ['video_id' => $secondVideo->id, 'score' => 0.74],
                ],
                'meta' => [
                    'source' => 'fastapi',
                ],
            ], 200),
        ]);

        $response = $this
            ->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=3');

        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $thirdVideo->id)
            ->assertJsonPath('data.1.id', (string) $firstVideo->id)
            ->assertJsonPath('data.2.id', (string) $secondVideo->id);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Kulsah-Timestamp')[0] ?? null;
            $signature = $request->header('X-Kulsah-Signature')[0] ?? null;

            if (! $timestamp || ! $signature) {
                return false;
            }

            $expected = hash_hmac(
                'sha256',
                implode("\n", [
                    $timestamp,
                    'POST',
                    '/recommend',
                    $request->body(),
                ]),
                'test-secret'
            );

            return hash_equals($expected, $signature);
        });
    }

    public function test_initial_feed_is_filtered_by_onboarding_vibes_before_fastapi_ranking(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', true);
        config()->set('services.fastapi.url', 'http://127.0.0.1:8001');
        config()->set('services.fastapi.shared_secret', 'test-secret');
        Cache::flush();

        $viewer = User::factory()->create([
            'username' => 'vibe_viewer',
        ]);

        Onboarding::create([
            'user_id' => $viewer->id,
            'vibe' => ['music'],
        ]);

        $musicCreator = User::factory()->create([
            'username' => 'music_creator',
        ]);
        $travelCreator = User::factory()->create([
            'username' => 'travel_creator',
        ]);

        $musicVideo = Video::create([
            'user_id' => $musicCreator->id,
            'title' => 'Afrobeats night',
            'caption' => 'music and dance',
            'content_type' => 'music',
            'content_types' => ['music', 'dance'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/music.mp4',
            'source_key' => 'videos/originals/1/music.mp4',
            'cdn_url' => 'https://cdn.example.com/music.mp4',
            'thumbnail_url' => 'https://cdn.example.com/music.jpg',
            'duration' => 18,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/music.m3u8',
            'metadata' => [],
        ]);

        Video::create([
            'user_id' => $travelCreator->id,
            'title' => 'Island travel vlog',
            'caption' => 'travel and exploration',
            'content_type' => 'travel',
            'content_types' => ['travel'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/travel.mp4',
            'source_key' => 'videos/originals/1/travel.mp4',
            'cdn_url' => 'https://cdn.example.com/travel.mp4',
            'thumbnail_url' => 'https://cdn.example.com/travel.jpg',
            'duration' => 24,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/travel.m3u8',
            'metadata' => [],
        ]);

        Http::fake([
            'http://127.0.0.1:8001/recommend' => Http::response([
                'user_id' => $viewer->id,
                'videos' => [
                    ['video_id' => $musicVideo->id, 'score' => 0.99],
                ],
                'meta' => [
                    'source' => 'fastapi',
                ],
            ], 200),
        ]);

        $response = $this
            ->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/feed?limit=5');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $musicVideo->id);

        Http::assertSent(function (Request $request) use ($viewer): bool {
            $payload = $request->data();

            return (int) ($payload['user_id'] ?? 0) === (int) $viewer->id
                && ($payload['initial_feed'] ?? false) === true
                && in_array('music', $payload['vibe_terms'] ?? [], true)
                && in_array('music', $payload['interest_terms'] ?? [], true)
                && count($payload['videos'] ?? []) === 1;
        });
    }

    public function test_recommendations_endpoint_returns_ranked_video_ids(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', true);
        config()->set('services.fastapi.url', 'http://127.0.0.1:8001');
        config()->set('services.fastapi.shared_secret', 'test-secret');
        Cache::flush();

        $viewer = User::factory()->create([
            'username' => 'recommend_viewer',
        ]);
        $followedCreator = User::factory()->create([
            'username' => 'recommend_creator_1',
        ]);
        $subscribedCreator = User::factory()->create([
            'username' => 'recommend_creator_2',
        ]);
        $thirdCreator = User::factory()->create([
            'username' => 'recommend_creator_3',
        ]);

        $firstVideo = Video::create([
            'user_id' => $followedCreator->id,
            'title' => 'Music match',
            'caption' => 'Music clip',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/1.mp4',
            'source_key' => 'videos/originals/1/1.mp4',
            'cdn_url' => 'https://cdn.example.com/1.mp4',
            'thumbnail_url' => 'https://cdn.example.com/1.jpg',
            'duration' => 20,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/1.m3u8',
            'metadata' => [],
        ]);

        $secondVideo = Video::create([
            'user_id' => $subscribedCreator->id,
            'title' => 'Dance match',
            'caption' => 'Dance clip',
            'content_type' => 'dance',
            'content_types' => ['dance'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/2.mp4',
            'source_key' => 'videos/originals/1/2.mp4',
            'cdn_url' => 'https://cdn.example.com/2.mp4',
            'thumbnail_url' => 'https://cdn.example.com/2.jpg',
            'duration' => 25,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/2.m3u8',
            'metadata' => [],
        ]);

        $thirdVideo = Video::create([
            'user_id' => $thirdCreator->id,
            'title' => 'Travel clip',
            'caption' => 'Travel content',
            'content_type' => 'travel',
            'content_types' => ['travel'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/3.mp4',
            'source_key' => 'videos/originals/1/3.mp4',
            'cdn_url' => 'https://cdn.example.com/3.mp4',
            'thumbnail_url' => 'https://cdn.example.com/3.jpg',
            'duration' => 30,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/3.m3u8',
            'metadata' => [],
        ]);

        UserFollow::create([
            'follower_id' => $viewer->id,
            'followed_id' => $followedCreator->id,
        ]);

        Subscription::create([
            'subscriber_id' => $viewer->id,
            'creator_id' => $subscribedCreator->id,
            'status' => 'active',
        ]);

        VideoLike::create([
            'video_id' => $firstVideo->id,
            'user_id' => $viewer->id,
        ]);

        VideoBookmark::create([
            'video_id' => $secondVideo->id,
            'user_id' => $viewer->id,
        ]);

        Http::fake([
            'http://127.0.0.1:8001/recommend' => Http::response([
                'user_id' => $viewer->id,
                'videos' => [
                    ['video_id' => $firstVideo->id, 'score' => 0.99],
                    ['video_id' => $secondVideo->id, 'score' => 0.88],
                    ['video_id' => $thirdVideo->id, 'score' => 0.7],
                ],
                'meta' => [
                    'source' => 'fastapi',
                ],
            ], 200),
        ]);

        $response = $this
            ->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson('/api/v1/general/recommendations?limit=3&search_query=music&interest_terms[]=music');

        $response->assertOk()
            ->assertJsonPath('data.0', $firstVideo->id)
            ->assertJsonPath('data.1', $secondVideo->id)
            ->assertJsonPath('data.2', $thirdVideo->id);

        Http::assertSent(function (Request $request) use ($viewer, $firstVideo, $secondVideo): bool {
            if ($request->url() !== 'http://127.0.0.1:8001/recommend') {
                return false;
            }

            $payload = $request->data();
            $timestamp = $request->header('X-Kulsah-Timestamp')[0] ?? null;
            $signature = $request->header('X-Kulsah-Signature')[0] ?? null;
            $expected = $timestamp && $signature
                ? hash_hmac(
                    'sha256',
                    implode("\n", [
                        $timestamp,
                        'POST',
                        '/recommend',
                        $request->body(),
                    ]),
                    'test-secret'
                )
                : null;

            return (int) ($payload['user_id'] ?? 0) === (int) $viewer->id
                && (array) ($payload['followed_creator_ids'] ?? []) === [(int) $firstVideo->user_id]
                && (array) ($payload['subscribed_creator_ids'] ?? []) === [(int) $secondVideo->user_id]
                && in_array('music', $payload['favorite_categories'] ?? [], true)
                && in_array($firstVideo->id, $payload['liked_video_ids'] ?? [], true)
                && in_array($secondVideo->id, $payload['bookmarked_video_ids'] ?? [], true)
                && count($payload['videos'] ?? []) === 3
                && array_key_exists('history_affinity', $payload['videos'][0] ?? [])
                && $timestamp !== null
                && $signature !== null
                && is_string($expected)
                && hash_equals($expected, $signature);
        });
    }
}
