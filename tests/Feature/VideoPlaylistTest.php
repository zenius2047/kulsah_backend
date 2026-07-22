<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoPlaylist;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VideoPlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_create_manage_and_attach_video_to_multiple_playlists(): void
    {
        config()->set('logging.default', 'null');

        $creator = User::factory()->create([
            'username' => 'playlist_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Playlist video',
            'caption' => 'A clip for a playlist',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/playlist-video.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/playlist-video.m3u8',
            'streaming_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/playlist-video.m3u8',
            'thumbnail_url' => 'https://example.com/playlist-video.jpg',
            'duration' => 45,
            'status' => 'ready',
            'metadata' => [],
        ]);

        $nextVideo = Video::create([
            'user_id' => $creator->id,
            'title' => 'Next playlist video',
            'caption' => 'Another clip for the same playlist',
            'content_type' => 'music',
            'content_types' => ['music'],
            'visibility' => 'public',
            'source_url' => 'https://example.com/next-source.mp4',
            'source_key' => 'videos/originals/1/next-playlist-video.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/next-playlist-video.m3u8',
            'streaming_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/next-playlist-video.m3u8',
            'thumbnail_url' => 'https://example.com/next-playlist-video.jpg',
            'duration' => 50,
            'status' => 'ready',
            'views_count' => 7,
            'metadata' => [],
        ]);

        $firstPlaylistResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/creator/video-playlists', [
                'name' => 'Workout Mix',
            ]);

        $firstPlaylistResponse->assertCreated()
            ->assertJsonPath('message', 'Video playlist created successfully.')
            ->assertJsonPath('data.name', 'Workout Mix');

        $firstPlaylistId = $firstPlaylistResponse->json('data.id');

        $secondPlaylistResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/creator/video-playlists', [
                'name' => 'Favorites',
            ]);

        $secondPlaylistResponse->assertCreated()
            ->assertJsonPath('data.name', 'Favorites');

        $secondPlaylistId = $secondPlaylistResponse->json('data.id');

        $listResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson('/api/v1/creator/video-playlists');

        $listResponse->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $showResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$firstPlaylistId}");

        $showResponse->assertOk()
            ->assertJsonPath('data.id', $firstPlaylistId)
            ->assertJsonCount(0, 'data.videos');

        $videosResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos");

        $videosResponse->assertOk()
            ->assertJsonPath('playlist_id', (string) $firstPlaylistId)
            ->assertJsonPath('playlist_name', 'Workout Mix')
            ->assertJsonPath('background', null)
            ->assertJsonPath('item', null)
            ->assertJsonCount(0, 'next_videos');

        $moveResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos/{$video->id}");

        $moveResponse->assertOk()
            ->assertJsonPath('message', 'Video added to playlist successfully.')
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.playlist_ids.0', $firstPlaylistId)
            ->assertJsonPath('data.playlists_count', 1);

        Cache::flush();

        $videosResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos");

        $videosResponse->assertOk()
            ->assertJsonPath('playlist_id', (string) $firstPlaylistId)
            ->assertJsonPath('playlist_name', 'Workout Mix')
            ->assertJsonPath('background', 'https://example.com/playlist-video.jpg')
            ->assertJsonPath('item.id', (string) $video->id)
            ->assertJsonPath('item.video', 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/playlist-video.m3u8')
            ->assertJsonPath('item.views', '0')
            ->assertJsonCount(0, 'next_videos');

        $moveNextResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos/{$nextVideo->id}");

        $moveNextResponse->assertOk()
            ->assertJsonPath('data.id', $nextVideo->id)
            ->assertJsonPath('data.playlist_ids.0', $firstPlaylistId)
            ->assertJsonPath('data.playlists_count', 1);

        Cache::flush();

        $videosResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos");

        $videosResponse->assertOk()
            ->assertJsonPath('playlist_id', (string) $firstPlaylistId)
            ->assertJsonPath('playlist_name', 'Workout Mix')
            ->assertJsonPath('background', 'https://example.com/next-playlist-video.jpg')
            ->assertJsonPath('item.id', (string) $nextVideo->id)
            ->assertJsonPath('item.video', 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/next-playlist-video.m3u8')
            ->assertJsonPath('item.views', '7')
            ->assertJsonCount(1, 'next_videos')
            ->assertJsonPath('next_videos.0.id', (string) $video->id)
            ->assertJsonPath('next_videos.0.views', '0');

        $this->assertDatabaseHas('video_playlist_video', [
            'video_playlist_id' => $firstPlaylistId,
            'video_id' => $video->id,
        ]);

        $attachSecondResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/video-playlists/{$secondPlaylistId}/videos/{$video->id}");

        $attachSecondResponse->assertOk()
            ->assertJsonPath('data.playlist_ids.0', $firstPlaylistId)
            ->assertJsonPath('data.playlist_ids.1', $secondPlaylistId)
            ->assertJsonPath('data.playlists_count', 2);

        $this->assertDatabaseHas('video_playlist_video', [
            'video_playlist_id' => $secondPlaylistId,
            'video_id' => $video->id,
        ]);

        $updateResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->patchJson("/api/v1/creator/video-playlists/{$firstPlaylistId}", [
                'name' => 'Workout Mix Updated',
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('message', 'Video playlist updated successfully.')
            ->assertJsonPath('data.name', 'Workout Mix Updated');

        $removeResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->deleteJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos/{$video->id}");

        $removeResponse->assertOk()
            ->assertJsonPath('message', 'Video removed from playlist successfully.')
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.playlist_ids.0', $secondPlaylistId)
            ->assertJsonPath('data.playlists_count', 1);

        $deletePlaylistResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->deleteJson("/api/v1/creator/video-playlists/{$secondPlaylistId}");

        $deletePlaylistResponse->assertOk()
            ->assertJsonPath('message', 'Video playlist deleted successfully.');

        $this->assertDatabaseMissing('video_playlists', [
            'id' => $secondPlaylistId,
        ]);
        $this->assertDatabaseMissing('video_playlist_video', [
            'video_playlist_id' => $secondPlaylistId,
            'video_id' => $video->id,
        ]);
    }

    public function test_creator_cannot_access_another_creators_playlist(): void
    {
        $creator = User::factory()->create(['username' => 'playlist_owner']);
        $intruder = User::factory()->create(['username' => 'playlist_intruder']);

        $playlist = VideoPlaylist::query()->create([
            'user_id' => $creator->id,
            'name' => 'Private playlist',
        ]);

        $response = $this
            ->actingAs($intruder, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$playlist->id}");

        $response->assertStatus(403);
    }
}
