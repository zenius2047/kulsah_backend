<?php

namespace Tests\Feature;

use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostGift;
use App\Models\CommunityPostLike;
use App\Models\CommunityPostShare;
use App\Models\Role;
use App\Models\KulCoinGift;
use App\Models\KulCoinWallet;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_create_text_community_post(): void
    {
        $creator = User::factory()->create([
            'username' => 'community_creator',
        ]);

        $response = $this->actingAs($creator)
            ->withoutMiddleware()
            ->postJson('/api/v1/creator/community/posts', [
                'type' => 'text',
                'content' => 'Hello community',
                'audience' => 'public',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Community post created successfully.')
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.content', 'Hello community')
            ->assertJsonPath('data.audience', 'public');

        $this->assertDatabaseHas('community_posts', [
            'user_id' => $creator->id,
            'type' => 'text',
            'content' => 'Hello community',
            'audience' => 'public',
        ]);
    }

    public function test_fan_cannot_create_community_post(): void
    {
        $fan = User::factory()->create([
            'username' => 'community_fan',
        ]);

        $fanRole = Role::create(['name' => 'fan']);
        $fan->roles()->attach($fanRole->id);

        $response = $this->actingAs($fan, 'sanctum')
            ->postJson('/api/v1/creator/community/posts', [
                'type' => 'text',
                'content' => 'I should not be able to post this',
                'audience' => 'public',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized user role');

        $this->assertDatabaseMissing('community_posts', [
            'user_id' => $fan->id,
            'content' => 'I should not be able to post this',
        ]);
    }

    public function test_creator_can_create_poll_community_post(): void
    {
        $creator = User::factory()->create([
            'username' => 'community_poll_creator',
        ]);
        $closesAt = now()->addDay()->toIso8601String();

        $response = $this->actingAs($creator)
            ->withoutMiddleware()
            ->postJson('/api/v1/creator/community/posts', [
                'type' => 'poll',
                'content' => 'What should we post next?',
                'audience' => 'subscribers',
                'poll' => [
                    'options' => ['Shorts', 'Tutorials', 'Behind the scenes'],
                    'closes_at' => $closesAt,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'poll')
            ->assertJsonPath('data.poll.options.0.text', 'Shorts')
            ->assertJsonPath('data.poll.closes_at', $closesAt);

        $this->assertDatabaseHas('community_posts', [
            'user_id' => $creator->id,
            'type' => 'poll',
            'audience' => 'subscribers',
        ]);
    }

    public function test_creator_can_create_community_post_with_media(): void
    {
        $creator = User::factory()->create([
            'username' => 'community_media_creator',
        ]);

        Storage::fake('community-media');
        config([
            'video.storage_disk' => 'community-media',
        ]);

        $this->mock(CloudinaryService::class, function ($mock): void {
            $mock->shouldReceive('uploadImageFromS3Key')
                ->once()
                ->andReturn([
                    'cdn_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'rendered_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'stream_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'streaming_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'cloudinary_public_id' => 'kulsah/community/sample',
                    'cloudinary_asset_id' => 'asset_123',
                    'thumbnail_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'poster_url' => 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg',
                    'metadata' => ['resource_type' => 'image'],
                ]);
        });

        $response = $this->actingAs($creator)
            ->withoutMiddleware()
            ->post('/api/v1/creator/community/posts', [
                'type' => 'image',
                'content' => 'Community cover art',
                'audience' => 'public',
                'media' => [
                    UploadedFile::fake()->image('community-cover.jpg'),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Community post created successfully.')
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.media.0.type', 'image')
            ->assertJsonPath('data.media.0.url', 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg')
            ->assertJsonPath('data.media.0.cloudinary_stream_url', 'https://res.cloudinary.com/demo/image/upload/v1/kulsah/community/sample.jpg');

        $this->assertDatabaseHas('community_posts', [
            'user_id' => $creator->id,
            'type' => 'image',
            'content' => 'Community cover art',
            'audience' => 'public',
        ]);

        $this->assertDatabaseCount('community_post_media', 1);

        $media = CommunityPostMedia::query()->first();
        $this->assertNotNull($media);
        $this->assertSame('image', $media->media_type);
        $this->assertSame('community-media', $media->disk);
        $this->assertTrue(Storage::disk('community-media')->exists($media->source_key));
    }

    public function test_subscribers_only_posts_are_hidden_from_non_subscribers(): void
    {
        $creator = User::factory()->create([
            'username' => 'community_private_creator',
        ]);
        $viewer = User::factory()->create([
            'username' => 'community_viewer',
        ]);

        $privatePost = CommunityPost::create([
            'user_id' => $creator->id,
            'type' => 'text',
            'content' => 'Subscriber only',
            'audience' => 'subscribers',
            'media_ids' => [],
            'poll' => null,
        ]);

        $this->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson("/api/v1/general/community/posts/{$privatePost->id}")
            ->assertStatus(403);

        Subscription::create([
            'subscriber_id' => $viewer->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        $this->actingAs($viewer)
            ->withoutMiddleware()
            ->getJson("/api/v1/general/community/posts/{$privatePost->id}")
            ->assertOk()
            ->assertJsonPath('data.id', 'post_'.$privatePost->id)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_user_can_comment_like_share_and_gift_community_post(): void
    {
        $creator = User::factory()->create([
            'username' => 'community_engagement_creator',
        ]);
        $viewer = User::factory()->create([
            'username' => 'community_engagement_viewer',
        ]);

        KulCoinWallet::create([
            'user_id' => $viewer->id,
            'account_name' => 'Viewer Wallet',
            'currency_code' => 'KC',
            'available_balance_kc' => 500,
            'bonus_balance_kc' => 0,
            'status' => 'active',
        ]);

        $gift = KulCoinGift::create([
            'code' => 'heart',
            'name' => 'Heart',
            'category' => 'community',
            'coin_cost' => 25,
            'sort_order' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);

        $post = CommunityPost::create([
            'user_id' => $creator->id,
            'type' => 'text',
            'content' => 'Community is live',
            'audience' => 'public',
            'media_ids' => [],
            'poll' => null,
        ]);

        $likeResponse = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/community/posts/{$post->id}/like");

        $likeResponse->assertOk()
            ->assertJsonPath('data.viewer.is_liked', true)
            ->assertJsonPath('data.stats.likes_count', 1)
            ->assertJsonPath('data.author.handle', 'community_engagement_creator');

        $this->assertDatabaseHas('community_post_likes', [
            'community_post_id' => $post->id,
            'user_id' => $viewer->id,
        ]);

        $commentResponse = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/community/posts/{$post->id}/comments", [
                'body' => 'Looks great',
            ]);

        $commentResponse->assertCreated()
            ->assertJsonPath('data.content', 'Looks great')
            ->assertJsonPath('data.author.handle', 'community_engagement_viewer');

        $this->assertDatabaseHas('community_post_comments', [
            'community_post_id' => $post->id,
            'user_id' => $viewer->id,
            'body' => 'Looks great',
        ]);

        $shareResponse = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/community/posts/{$post->id}/share");

        $shareResponse->assertOk()
            ->assertJsonPath('data.viewer.can_view', true)
            ->assertJsonPath('data.stats.shares_count', 1);

        $this->assertDatabaseHas('community_post_shares', [
            'community_post_id' => $post->id,
            'user_id' => $viewer->id,
        ]);

        $giftResponse = $this->actingAs($viewer)
            ->withoutMiddleware()
            ->postJson("/api/v1/general/community/posts/{$post->id}/gift", [
                'gift_id' => $gift->id,
                'quantity' => 2,
                'message' => 'Keep going',
            ]);

        $giftResponse->assertCreated()
            ->assertJsonPath('message', 'Community post gifted successfully.')
            ->assertJsonPath('data.post.stats.gifts_count', 1)
            ->assertJsonPath('data.gift.quantity', 2)
            ->assertJsonPath('data.gift.coin_amount', 50);

        $this->assertDatabaseHas('community_post_gifts', [
            'community_post_id' => $post->id,
            'sender_id' => $viewer->id,
            'recipient_user_id' => $creator->id,
            'gift_id' => $gift->id,
            'quantity' => 2,
            'coin_amount' => 50,
        ]);
    }
}
