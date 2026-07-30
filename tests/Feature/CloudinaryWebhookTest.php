<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Models\CloudinaryWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudinaryWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudinary_webhook_marks_video_ready_and_is_idempotent(): void
    {
        config()->set('services.cloudinary.api_secret', 'test-secret');

        $creator = User::factory()->create([
            'username' => 'webhook_creator',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Webhook video',
            'caption' => 'Waiting for render',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/webhook.mp4',
            'status' => 'processing',
            'render_status' => 'processing',
            'cloudinary_render_id' => 'batch-123',
            'metadata' => [
                'cloudinary_render_public_id' => 'renders/1/webhook-render',
            ],
        ]);

        $payload = [
            'notification_id' => 'notif-123',
            'batch_id' => 'batch-123',
            'public_id' => 'renders/1/webhook-render',
            'status' => 'completed',
            'asset_id' => 'asset-999',
            'secure_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/renders/1/webhook-render.m3u8',
            'eager' => [
                [
                    'secure_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/renders/1/webhook-render.m3u8',
                ],
                [
                    'secure_url' => 'https://res.cloudinary.com/demo/video/upload/so_0,w_720,h_1280,c_fill,f_jpg,q_auto/renders/1/webhook-render',
                ],
            ],
        ];

        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = sha1($body.$timestamp.'test-secret');

        $first = $this
            ->postJson('/api/v1/cloudinary/webhook', $payload, [
                'X-Cld-Timestamp' => $timestamp,
                'X-Cld-Signature' => $signature,
            ]);

        $first->assertOk()
            ->assertJsonPath('status', 'processed');

        $video->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertSame('ready', $video->render_status);
        $this->assertSame('https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/renders/1/webhook-render.m3u8', $video->streaming_url);
        $this->assertSame('https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/renders/1/webhook-render.m3u8', $video->rendered_url);
        $this->assertNotNull($video->render_completed_at);

        $this->assertDatabaseHas('cloudinary_webhook_events', [
            'event_id' => 'batch-123',
            'event_type' => 'completed',
        ]);

        $second = $this
            ->postJson('/api/v1/cloudinary/webhook', $payload, [
                'X-Cld-Timestamp' => $timestamp,
                'X-Cld-Signature' => $signature,
            ]);

        $second->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, CloudinaryWebhookEvent::query()->count());
    }

    public function test_cloudinary_webhook_marks_video_failed(): void
    {
        config()->set('services.cloudinary.api_secret', 'test-secret');

        $creator = User::factory()->create([
            'username' => 'webhook_creator_fail',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Failed video',
            'caption' => 'Waiting for render',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/failing.mp4',
            'status' => 'processing',
            'render_status' => 'processing',
            'cloudinary_render_id' => 'batch-fail',
        ]);

        $payload = [
            'notification_id' => 'notif-fail',
            'batch_id' => 'batch-fail',
            'public_id' => 'renders/1/failing-render',
            'status' => 'failed',
            'error' => 'Cloudinary could not finish the render.',
        ];

        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = sha1($body.$timestamp.'test-secret');

        $response = $this
            ->postJson('/api/v1/cloudinary/webhook', $payload, [
                'X-Cld-Timestamp' => $timestamp,
                'X-Cld-Signature' => $signature,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'failed');

        $video->refresh();

        $this->assertSame('failed', $video->status);
        $this->assertSame('failed', $video->render_status);
        $this->assertSame('Cloudinary could not finish the render.', data_get($video->metadata, 'render_error'));
    }

    public function test_cloudinary_webhook_resolves_video_from_render_public_id_shape(): void
    {
        config()->set('services.cloudinary.api_secret', 'test-secret');

        $creator = User::factory()->create([
            'username' => 'webhook_creator_render_public_id',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Render public id video',
            'caption' => 'Waiting for render',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/render-public-id.mp4',
            'status' => 'processing',
            'render_status' => 'processing',
            'metadata' => [
                'cloudinary_render_public_id' => '1/render-public-id-abc123',
            ],
        ]);

        $payload = [
            'batch_id' => '33a448bf441d99e35d96e32050bf59a0960317d8d8d1671dc21a737e1a50da0afc609a6c534190bfa5a889615362e071',
            'request_id' => '10cc2583d0f03005a8aae10811a4c87b',
            'asset_id' => 'asset-render-public-id',
            'public_id' => 'kulsah/videos/renders/1/render-public-id-abc123',
            'notification_type' => 'eager_completed',
            'eager' => [
                [
                    'secure_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/kulsah/videos/renders/1/render-public-id-abc123.m3u8',
                ],
            ],
        ];

        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = sha1($body.$timestamp.'test-secret');

        $response = $this->postJson('/api/v1/cloudinary/webhook', $payload, [
            'X-Cld-Timestamp' => $timestamp,
            'X-Cld-Signature' => $signature,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed');

        $video->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertSame('ready', $video->render_status);
        $this->assertSame('asset-render-public-id', $video->cloudinary_asset_id);
    }

    public function test_cloudinary_webhook_can_resolve_video_from_context_video_id(): void
    {
        config()->set('services.cloudinary.api_secret', 'test-secret');

        $creator = User::factory()->create([
            'username' => 'webhook_creator_context',
        ]);
        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Context video',
            'caption' => 'Waiting for render',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/context.mp4',
            'status' => 'processing',
            'render_status' => 'processing',
            'metadata' => [
                'cloudinary_render_public_id' => 'renders/1/context-render',
            ],
        ]);

        $payload = [
            'notification_id' => 'notif-context',
            'public_id' => 'renders/1/context-render',
            'context' => 'video_id='.$video->id.'|render_hash=abc123',
            'status' => 'completed',
            'asset_id' => 'asset-context',
            'secure_url' => 'https://res.cloudinary.com/demo/video/upload/sp_auto:maxres_2160p/renders/1/context-render.m3u8',
        ];

        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = sha1($body.$timestamp.'test-secret');

        $response = $this->postJson('/api/v1/cloudinary/webhook', $payload, [
            'X-Cld-Timestamp' => $timestamp,
            'X-Cld-Signature' => $signature,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed');

        $video->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertSame('ready', $video->render_status);
        $this->assertSame('asset-context', $video->cloudinary_asset_id);
    }
}
