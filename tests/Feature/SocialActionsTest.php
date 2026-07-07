<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_like_bookmark_follow_and_comment(): void
    {
        $viewer = User::factory()->create([
            'name' => 'Viewer One',
            'username' => 'viewer_1',
        ]);
        $creator = User::factory()->create([
            'name' => 'Creator One',
            'username' => 'creator_1',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Test video',
            'caption' => 'Testing social actions',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/example.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/example.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/example.jpg',
            'duration' => 30,
            'status' => 'ready',
            'metadata' => [],
        ]);

        $like = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/videos/{$video->id}/like");

        $like->assertOk()
            ->assertJsonPath('data.isLiked', true);

        $this->assertDatabaseHas('video_likes', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        $unlike = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->deleteJson("/api/v1/general/videos/{$video->id}/like");

        $unlike->assertOk()
            ->assertJsonPath('data.isLiked', false);

        $this->assertDatabaseMissing('video_likes', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        $bookmark = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/videos/{$video->id}/bookmark");

        $bookmark->assertOk()
            ->assertJsonPath('data.isBookmarked', true);

        $this->assertDatabaseHas('video_bookmarks', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        $follow = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/creators/{$creator->id}/follow");

        $follow->assertOk()
            ->assertJsonPath('data.following', true);

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $viewer->id,
            'followed_id' => $creator->id,
        ]);

        $comment = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/videos/{$video->id}/comments", [
                'body' => 'Nice work',
            ]);

        $comment->assertCreated()
            ->assertJsonPath('data.body', 'Nice work');

        $commentId = $comment->json('data.id');

        $reply = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/videos/{$video->id}/comments/{$commentId}/reply", [
                'body' => 'Thanks!',
            ]);

        $reply->assertCreated()
            ->assertJsonPath('data.parent_id', $commentId)
            ->assertJsonPath('data.body', 'Thanks!');

        $this->assertDatabaseHas('video_comments', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'body' => 'Nice work',
        ]);

        $this->assertDatabaseHas('video_comments', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'body' => 'Thanks!',
            'parent_id' => $commentId,
        ]);
    }

    public function test_user_can_unbookmark_and_unfollow(): void
    {
        $viewer = User::factory()->create([
            'username' => 'viewer_2',
        ]);
        $creator = User::factory()->create([
            'username' => 'creator_2',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Test video',
            'caption' => 'Testing social actions',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/example.mp4',
            'cdn_url' => 'https://res.cloudinary.com/demo/video/upload/example.mp4',
            'thumbnail_url' => 'https://res.cloudinary.com/demo/video/upload/example.jpg',
            'duration' => 30,
            'status' => 'ready',
            'metadata' => [],
        ]);

        VideoBookmark::create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);

        UserFollow::create([
            'follower_id' => $viewer->id,
            'followed_id' => $creator->id,
        ]);

        Subscription::create([
            'subscriber_id' => $viewer->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        $unbookmark = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->deleteJson("/api/v1/general/videos/{$video->id}/bookmark");

        $unbookmark->assertOk()
            ->assertJsonPath('data.isBookmarked', false);

        $unfollow = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->deleteJson("/api/v1/general/creators/{$creator->id}/follow");

        $unfollow->assertOk()
            ->assertJsonPath('data.following', false);
    }
}
