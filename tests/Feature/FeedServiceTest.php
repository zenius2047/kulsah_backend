<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FeedServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_service_paginates_and_invalidates_cache_versions(): void
    {
        config()->set('cache.default', 'array');
        Cache::flush();

        $user = User::factory()->create([
            'username' => 'feed_user',
        ]);
        $creator = User::factory()->create([
            'username' => 'feed_creator',
        ]);

        Video::create([
            'user_id' => $creator->id,
            'title' => 'Video 1',
            'caption' => 'First clip',
            'source_url' => 'https://example.com/1.mp4',
            'source_key' => 'videos/originals/1/1.mp4',
            'cdn_url' => 'https://cdn.example.com/1.mp4',
            'thumbnail_url' => 'https://cdn.example.com/1.jpg',
            'duration' => 10,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/1.m3u8',
            'metadata' => ['topic' => 'music'],
        ]);

        Video::create([
            'user_id' => $creator->id,
            'title' => 'Video 2',
            'caption' => 'Second clip',
            'source_url' => 'https://example.com/2.mp4',
            'source_key' => 'videos/originals/1/2.mp4',
            'cdn_url' => 'https://cdn.example.com/2.mp4',
            'thumbnail_url' => 'https://cdn.example.com/2.jpg',
            'duration' => 11,
            'status' => 'ready',
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => 'https://cdn.example.com/2.m3u8',
            'metadata' => ['topic' => 'dance'],
        ]);

        $service = app(FeedService::class);

        $firstPage = $service->getFeed($user->id, limit: 1, page: 1);
        $secondPage = $service->getFeed($user->id, limit: 1, page: 2);

        $this->assertCount(1, $firstPage['data']);
        $this->assertCount(1, $secondPage['data']);
        $this->assertNotSame($firstPage['cache_key'], $secondPage['cache_key']);

        $oldKey = $firstPage['cache_key'];
        $service->invalidateFeedCaches();

        $newPage = $service->getFeed($user->id, limit: 1, page: 1);

        $this->assertNotSame($oldKey, $newPage['cache_key']);
    }
}
