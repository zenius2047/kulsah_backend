<?php

namespace Tests\Feature;

use App\Jobs\RenderVideoEditsJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoRenderTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_queue_a_cloudinary_timeline_render(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'timeline_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Timeline video',
            'caption' => 'Render timeline test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/timeline.mp4',
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'layers' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello Kulsah',
                        'font' => 'Arial',
                        'size' => 60,
                        'color' => '#FFFFFF',
                        'x' => 100,
                        'y' => 200,
                        'start' => 0,
                        'end' => 5,
                    ],
                    [
                        'type' => 'sticker',
                        'public_id' => 'stickers/fire',
                        'x' => 500,
                        'y' => 600,
                        'start' => 1,
                        'end' => 10,
                    ],
                    [
                        'type' => 'drawing',
                        'asset_url' => 'https://example.com/drawing.png',
                        'x' => 200,
                        'y' => 300,
                        'width' => 400,
                        'height' => 200,
                        'start' => 2,
                        'end' => 8,
                    ],
                ],
                'filters' => [
                    'brightness' => 20,
                    'contrast' => 10,
                    'saturation' => 15,
                ],
                'trim' => [
                    'start' => 1,
                    'end' => 11,
                ],
                'output' => [
                    'format' => 'mp4',
                    'quality' => 'auto',
                    'width' => 720,
                    'height' => 1280,
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Video edit queued successfully.')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.render_status', 'queued')
            ->assertJsonPath('data.metadata.edit_status', 'queued')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.type', 'text')
            ->assertJsonPath('data.metadata.render_timeline.layers.1.type', 'sticker')
            ->assertJsonPath('data.metadata.render_timeline.layers.2.type', 'drawing');

        Queue::assertPushed(RenderVideoEditsJob::class);
    }
}
