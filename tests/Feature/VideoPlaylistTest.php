<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoPlaylist;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'thumbnail_url' => 'https://example.com/playlist-video.jpg',
            'duration' => 45,
            'status' => 'ready',
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
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $moveResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos/{$video->id}");

        $moveResponse->assertOk()
            ->assertJsonPath('message', 'Video added to playlist successfully.')
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.playlist_ids.0', $firstPlaylistId)
            ->assertJsonPath('data.playlists_count', 1);

        $videosResponse = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->getJson("/api/v1/creator/video-playlists/{$firstPlaylistId}/videos");

        $videosResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('meta.total', 1);

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
