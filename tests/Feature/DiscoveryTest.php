<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_returns_creators_events_and_trending_videos(): void
    {
        $fanRole = Role::query()->create(['name' => 'fan']);
        $creatorRole = Role::query()->create(['name' => 'creator']);

        $viewer = User::factory()->create([
            'username' => 'discovery_viewer',
        ]);
        $viewer->roles()->attach($fanRole);

        $creator = User::factory()->create([
            'name' => 'Godfred Kofi',
            'username' => '_godfred',
            'avatar' => 'https://example.com/avatar.png',
            'bio' => 'VFX creator and filmmaker',
            'verified' => true,
        ]);
        $creator->roles()->attach($creatorRole);

        UserFollow::query()->create([
            'follower_id' => $viewer->id,
            'followed_id' => $creator->id,
        ]);

        SubscriptionPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Premium',
            'price' => 25,
            'currency' => 'GHS',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $event = Event::query()->create([
            'user_id' => $creator->id,
            'title' => 'Mastering Cinematic Color Grading',
            'description' => 'A VFX color-grading workshop.',
            'category' => 'VFX',
            'venue_type' => 'hybrid',
            'venue_name' => 'Metropolis Theater & Virtual Dome',
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(4)->addHours(4),
            'timezone' => 'UTC',
            'capacity' => 100,
            'currency' => 'KUL',
            'cover_image_url' => 'https://example.com/event-cover.jpg',
            'ticket_types' => [
                [
                    'name' => 'General',
                    'price' => 120,
                    'quantity' => 100,
                    'sold_quantity' => 5,
                ],
            ],
            'status' => 'published',
            'tickets_sold' => 5,
        ]);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'title' => 'How I filmed this cyberpunk look',
            'caption' => 'A practical VFX breakdown',
            'content_type' => 'VFX Tutorial',
            'source_key' => "videos/originals/{$creator->id}/cyberpunk.mp4",
            'streaming_url' => 'https://example.com/video.m3u8',
            'poster_url' => 'https://example.com/thumbnail.jpg',
            'duration' => 525,
            'status' => 'ready',
            'views_count' => 4800000,
        ]);

        VideoLike::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);
        VideoBookmark::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);
        VideoComment::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'body' => 'Excellent breakdown.',
        ]);

        $response = $this
            ->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/general/discovery?tab=all&page=1&limit=20&search_query=vfx');

        $response->assertOk()
            ->assertJsonPath('data.creators.0.id', $creator->id)
            ->assertJsonPath('data.creators.0.handle', '_godfred')
            ->assertJsonPath('data.creators.0.is_verified', true)
            ->assertJsonPath('data.creators.0.is_live', false)
            ->assertJsonPath('data.creators.0.is_following', true)
            ->assertJsonPath('data.creators.0.is_premium', true)
            ->assertJsonPath('data.creators.0.followers_count', 1)
            ->assertJsonPath('data.creators.0.style', null)
            ->assertJsonPath('data.creators.0.tools', [])
            ->assertJsonPath('data.events.0.id', $event->id)
            ->assertJsonPath('data.events.0.location_type', 'hybrid')
            ->assertJsonPath('data.events.0.duration_minutes', 240)
            ->assertJsonPath('data.events.0.tickets_available', true)
            ->assertJsonPath('data.events.0.minimum_ticket_price', 120)
            ->assertJsonPath('data.videos.0.id', $video->id)
            ->assertJsonPath('data.videos.0.content_type', 'video')
            ->assertJsonPath('data.videos.0.category', 'VFX Tutorial')
            ->assertJsonPath('data.videos.0.stats.views_count', 4800000)
            ->assertJsonPath('data.videos.0.stats.likes_count', 1)
            ->assertJsonPath('data.videos.0.stats.comments_count', 1)
            ->assertJsonPath('data.videos.0.viewer.is_liked', true)
            ->assertJsonPath('data.videos.0.viewer.is_bookmarked', true)
            ->assertJsonPath('data.videos.0.viewer.is_following_creator', true)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 20)
            ->assertJsonPath('meta.pagination.has_more', false)
            ->assertJsonMissingPath('data.challenges');
    }

    public function test_discovery_tab_keeps_a_stable_shape_and_reports_more_results(): void
    {
        $fanRole = Role::query()->create(['name' => 'fan']);
        $creatorRole = Role::query()->create(['name' => 'creator']);

        $viewer = User::factory()->create(['username' => 'tab_viewer']);
        $viewer->roles()->attach($fanRole);
        $creator = User::factory()->create(['username' => 'tab_creator']);
        $creator->roles()->attach($creatorRole);

        foreach ([100, 200] as $views) {
            Video::query()->create([
                'user_id' => $creator->id,
                'title' => "VFX video {$views}",
                'content_type' => 'VFX',
                'source_key' => "videos/originals/{$creator->id}/{$views}.mp4",
                'cdn_url' => "https://example.com/{$views}.mp4",
                'status' => 'ready',
                'views_count' => $views,
            ]);
        }

        $response = $this
            ->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/general/discovery?tab=videos&page=1&limit=1&search_query=vfx');

        $response->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonCount(0, 'data.creators')
            ->assertJsonCount(0, 'data.events')
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.stats.views_count', 200)
            ->assertJsonPath('meta.pagination.has_more', true);

        $this
            ->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/general/discovery?tab=videos&page=1&limit=1&search_query=vfx')
            ->assertOk()
            ->assertHeader('X-Cache', 'HIT');
    }
}
