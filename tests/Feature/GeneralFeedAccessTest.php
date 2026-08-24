<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GeneralFeedAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_access_public_feed_without_authentication(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', false);
        Cache::flush();

        $creator = User::factory()->create();
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Guest clip',
            'caption' => 'Public feed video',
            'visibility' => 'public',
            'source_url' => 'https://example.com/guest.mp4',
            'source_key' => 'videos/originals/guest.mp4',
            'cdn_url' => 'https://cdn.example.com/guest.mp4',
            'thumbnail_url' => 'https://cdn.example.com/guest.jpg',
            'duration' => 12,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/guest.m3u8',
            'metadata' => ['topic' => 'music'],
        ]);

        $response = $this->getJson('/api/v1/general/feed?limit=10');

        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $video->id);
    }

    public function test_invalid_bearer_token_is_rejected_for_public_feed(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.fastapi.enabled', false);
        Cache::flush();

        $response = $this
            ->withHeader('Authorization', 'Bearer definitely-not-a-valid-token')
            ->getJson('/api/v1/general/feed?limit=10');

        $response->assertUnauthorized();
    }
}
