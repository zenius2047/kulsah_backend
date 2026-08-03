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

    public function test_creator_can_queue_a_timeline_with_a_long_asset_url(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'long_asset_url_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Long asset url video',
            'caption' => 'Render timeline test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/long-asset-url.mp4',
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'layers' => [
                    [
                        'type' => 'drawing',
                        'asset_url' => 'https://example.com/'.str_repeat('a', 8100).'.png',
                        'x' => 200,
                        'y' => 300,
                        'width' => 400,
                        'height' => 200,
                        'start' => 2,
                        'end' => 8,
                    ],
                ],
                'output' => [
                    'format' => 'mp4',
                    'quality' => 'auto',
                    'width' => 720,
                    'height' => 1280,
                ],
            ]);

        $response->assertAccepted();

        Queue::assertPushed(RenderVideoEditsJob::class);
    }

    public function test_creator_can_queue_a_rich_version_two_project_render(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'rich_project_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Rich project video',
            'caption' => 'Render project test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/rich-project.mp4',
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'project' => [
                    'version' => 2,
                    'project_id' => 'project-123',
                    'duration' => 12,
                    'canvas' => [
                        'width' => 1080,
                        'height' => 1920,
                        'fps' => 30,
                        'background_color' => '#000000',
                    ],
                    'tracks' => [
                        [
                            'id' => 'text-1',
                            'type' => 'text',
                            'start' => 1,
                            'end' => 6,
                            'z_index' => 20,
                            'preset' => 'subtitle',
                            'content' => [
                                'text' => 'Hello Kulsah',
                                'font_family' => 'Poppins',
                                'font_key' => 'Poppins',
                                'font_size' => 64,
                                'alignment' => 'center',
                                'color' => '#FFFFFF',
                                'preset' => 'subtitle',
                            ],
                            'background' => [
                                'enabled' => true,
                                'color' => '#000000',
                            ],
                            'stroke' => [
                                'width' => 3,
                                'color' => '#000000',
                            ],
                            'shadow' => [
                                'x' => 4,
                                'y' => 4,
                                'color' => 'black@0.6',
                            ],
                            'transition' => [
                                'enter' => 'fade_in',
                                'exit' => 'fade_out',
                                'duration' => 0.35,
                            ],
                            'animation' => [
                                'preset' => 'slide_up',
                                'duration' => 0.35,
                            ],
                            'keyframes' => [
                                [
                                    'time' => 1,
                                    'x' => 540,
                                    'y' => 440,
                                    'opacity' => 0,
                                ],
                                [
                                    'time' => 2,
                                    'x' => 540,
                                    'y' => 400,
                                    'opacity' => 1,
                                ],
                            ],
                            'transform' => [
                                'x' => 540,
                                'y' => 400,
                            ],
                        ],
                    ],
                    'output' => [
                        'format' => 'mp4',
                        'quality' => 'auto',
                        'width' => 1080,
                        'height' => 1920,
                    ],
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Video edit queued successfully.')
            ->assertJsonPath('data.metadata.edit_renderer', 'ffmpeg')
            ->assertJsonPath('data.metadata.schema_version', 2)
            ->assertJsonPath('data.metadata.edit_project.version', 2)
            ->assertJsonPath('data.metadata.edit_project.tracks.0.type', 'text')
            ->assertJsonPath('data.metadata.edit_project.tracks.0.content.font_key', 'Poppins')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.text', 'Hello Kulsah')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.font', 'Poppins');

        Queue::assertPushed(RenderVideoEditsJob::class);
    }

    public function test_creator_can_queue_a_rich_project_render_without_track_end_times(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'open_ended_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Open ended project video',
            'caption' => 'Render project test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/open-ended.mp4',
            'duration' => 65,
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'project' => [
                    'version' => 2,
                    'project_id' => 'open-ended-project',
                    'canvas' => [
                        'width' => 1080,
                        'height' => 1920,
                        'fps' => 30,
                        'background_color' => '#000000',
                    ],
                    'tracks' => [
                        [
                            'id' => 'text-1',
                            'type' => 'text',
                            'start' => 0,
                            'z_index' => 20,
                            'content' => [
                                'text' => 'Stays on screen until the video ends',
                                'font_family' => 'Poppins',
                                'font_key' => 'Poppins',
                                'font_size' => 64,
                                'alignment' => 'center',
                                'color' => '#FFFFFF',
                            ],
                            'background' => [
                                'enabled' => true,
                                'color' => '#000000',
                            ],
                        ],
                    ],
                    'output' => [
                        'format' => 'mp4',
                        'quality' => 'auto',
                        'width' => 1080,
                        'height' => 1920,
                    ],
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Video edit queued successfully.')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.render_status', 'queued')
            ->assertJsonPath('data.metadata.edit_project.duration', 65)
            ->assertJsonPath('data.metadata.edit_project.tracks.0.end', 65)
            ->assertJsonPath('data.metadata.render_timeline.layers.0.end', 65);

        Queue::assertPushed(RenderVideoEditsJob::class);
    }

    public function test_creator_can_queue_a_v3_project_render(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'v3_project_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'V3 project video',
            'caption' => 'Render project test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/v3-project.mp4',
            'duration' => 18,
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'schemaVersion' => '3.0.0',
                'metadata' => [
                    'id' => 'project-v3',
                    'name' => 'V3 Project',
                    'description' => 'Frontend schema v3',
                    'createdAt' => '2026-08-03T11:00:00Z',
                    'updatedAt' => '2026-08-03T11:05:00Z',
                    'createdBy' => (string) $creator->id,
                    'duration' => 18,
                    'revision' => 1,
                    'source' => 'web',
                ],
                'canvas' => [
                    'width' => 1080,
                    'height' => 1920,
                    'aspectRatio' => '9:16',
                    'fps' => 30,
                    'duration' => 18,
                    'backgroundColor' => '#000000',
                    'background' => [
                        'type' => 'color',
                        'color' => '#000000',
                        'opacity' => 1,
                    ],
                    'safeArea' => [
                        'enabled' => true,
                        'top' => 120,
                        'right' => 80,
                        'bottom' => 120,
                        'left' => 80,
                    ],
                ],
                'output' => [
                    'format' => 'mp4',
                    'quality' => 'high',
                    'width' => 1080,
                    'height' => 1920,
                    'fps' => 30,
                    'videoCodec' => 'h264',
                    'audioCodec' => 'aac',
                    'encoderPreset' => 'veryfast',
                    'pixelFormat' => 'yuv420p',
                    'fastStart' => true,
                ],
                'assets' => [
                    [
                        'id' => 'asset-image-1',
                        'type' => 'image',
                        'storageProvider' => 'cloudinary',
                        'storageKey' => 'samples/v3/image-1',
                        'url' => null,
                        'mimeType' => 'image/png',
                        'fileName' => 'image-1.png',
                        'width' => 800,
                        'height' => 800,
                    ],
                ],
                'scenes' => [
                    [
                        'id' => 'scene-1',
                        'name' => 'Intro',
                        'order' => 0,
                        'timeline' => [
                            'start' => 0,
                            'duration' => 18,
                        ],
                        'background' => [
                            'type' => 'color',
                            'color' => '#000000',
                            'opacity' => 1,
                        ],
                        'tracks' => [
                            [
                                'id' => 'track-text-1',
                                'type' => 'text',
                                'name' => 'Title',
                                'layer' => 1,
                                'enabled' => true,
                                'visible' => true,
                                'locked' => false,
                                'muted' => false,
                                'selected' => false,
                                'timeline' => [
                                    'start' => 0,
                                    'duration' => 18,
                                    'trimStart' => 0,
                                    'trimEnd' => 18,
                                    'playbackRate' => 1,
                                    'reverse' => false,
                                    'loop' => false,
                                    'freezeAtEnd' => false,
                                ],
                                'transform' => [
                                    'position' => ['x' => 120, 'y' => 240],
                                    'size' => ['width' => 720, 'height' => 160],
                                    'scale' => ['x' => 1, 'y' => 1, 'uniform' => true],
                                    'anchor' => ['preset' => 'center', 'x' => 0.5, 'y' => 0.5],
                                    'rotation' => 0,
                                    'skew' => ['x' => 0, 'y' => 0],
                                    'opacity' => 1,
                                    'flipHorizontal' => false,
                                    'flipVertical' => false,
                                ],
                                'content' => [
                                    'text' => 'Hello v3',
                                    'runs' => [],
                                ],
                                'textStyle' => [
                                    'fontFamily' => 'Arial',
                                    'fontSize' => 48,
                                    'fontWeight' => 700,
                                    'fontStyle' => 'normal',
                                    'color' => '#FFFFFF',
                                    'opacity' => 1,
                                    'alignment' => 'center',
                                    'verticalAlignment' => 'middle',
                                    'direction' => 'ltr',
                                    'lineHeight' => 1.2,
                                    'letterSpacing' => 0,
                                    'wordSpacing' => 0,
                                    'paragraphSpacing' => 0,
                                    'textTransform' => 'none',
                                    'maxWidth' => null,
                                    'maxHeight' => null,
                                    'maxLines' => null,
                                    'autoWrap' => true,
                                    'autoFit' => true,
                                    'autoShrink' => false,
                                    'overflow' => 'hidden',
                                    'fill' => [
                                        'type' => 'solid',
                                        'color' => '#FFFFFF',
                                        'opacity' => 1,
                                        'gradient' => null,
                                    ],
                                    'stroke' => [
                                        'enabled' => false,
                                        'color' => '#000000',
                                        'width' => 0,
                                        'opacity' => 1,
                                        'join' => 'round',
                                    ],
                                    'shadow' => [
                                        'enabled' => false,
                                        'color' => '#000000',
                                        'opacity' => 0,
                                        'blur' => 0,
                                        'spread' => 0,
                                        'offsetX' => 0,
                                        'offsetY' => 0,
                                    ],
                                    'glow' => [
                                        'enabled' => false,
                                        'color' => '#FFFFFF',
                                        'opacity' => 0,
                                        'blur' => 0,
                                        'spread' => 0,
                                        'intensity' => 0,
                                    ],
                                ],
                                'textBox' => [
                                    'padding' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
                                    'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                                    'background' => [
                                        'enabled' => true,
                                        'type' => 'solid',
                                        'color' => '#000000',
                                        'opacity' => 0.35,
                                        'gradient' => null,
                                        'blur' => 0,
                                    ],
                                    'border' => [
                                        'enabled' => false,
                                        'color' => '#000000',
                                        'width' => 0,
                                        'opacity' => 1,
                                        'style' => 'solid',
                                        'radius' => [
                                            'topLeft' => 0,
                                            'topRight' => 0,
                                            'bottomRight' => 0,
                                            'bottomLeft' => 0,
                                        ],
                                    ],
                                    'radius' => [
                                        'topLeft' => 0,
                                        'topRight' => 0,
                                        'bottomRight' => 0,
                                        'bottomLeft' => 0,
                                    ],
                                ],
                            ],
                            [
                                'id' => 'track-image-1',
                                'type' => 'image',
                                'name' => 'Sticker',
                                'layer' => 2,
                                'timeline' => [
                                    'start' => 2,
                                    'duration' => 8,
                                    'trimStart' => 0,
                                    'trimEnd' => 8,
                                    'playbackRate' => 1,
                                    'reverse' => false,
                                    'loop' => false,
                                    'freezeAtEnd' => false,
                                ],
                                'transform' => [
                                    'position' => ['x' => 500, 'y' => 900],
                                    'size' => ['width' => 320, 'height' => 320],
                                    'scale' => ['x' => 1, 'y' => 1, 'uniform' => true],
                                    'anchor' => ['preset' => 'center', 'x' => 0.5, 'y' => 0.5],
                                    'rotation' => 0,
                                    'skew' => ['x' => 0, 'y' => 0],
                                    'opacity' => 1,
                                    'flipHorizontal' => false,
                                    'flipVertical' => false,
                                ],
                                'source' => [
                                    'assetId' => 'asset-image-1',
                                    'fallbackUrl' => null,
                                ],
                                'fit' => 'contain',
                            ],
                        ],
                        'transitionIn' => null,
                        'transitionOut' => null,
                        'enabled' => true,
                    ],
                ],
                'globalAudioTracks' => [],
                'globalEffects' => [],
                'guides' => [
                    'showSafeArea' => true,
                    'showCenterGuides' => true,
                    'showRuleOfThirds' => false,
                    'showBoundingBoxes' => true,
                    'snappingEnabled' => true,
                    'snapThreshold' => 8,
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Video edit queued successfully.')
            ->assertJsonPath('data.metadata.schema_version', 3)
            ->assertJsonPath('data.metadata.edit_project.schemaVersion', '3.0.0')
            ->assertJsonPath('data.metadata.edit_project.metadata.name', 'V3 Project')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.type', 'text')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.text', 'Hello v3')
            ->assertJsonPath('data.metadata.render_timeline.layers.1.type', 'image');

        Queue::assertPushed(RenderVideoEditsJob::class);
    }

    public function test_creator_can_queue_rich_text_with_stroke_shadow_and_transition_metadata(): void
    {
        config()->set('logging.default', 'null');
        Queue::fake();

        $creator = User::factory()->create([
            'username' => 'rich_project_unsupported_creator',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'title' => 'Unsupported rich project video',
            'caption' => 'Render project test',
            'visibility' => 'public',
            'source_url' => 'https://example.com/source.mp4',
            'source_key' => 'videos/originals/1/rich-project-unsupported.mp4',
            'status' => 'ready',
            'metadata' => [],
        ]);

        $response = $this
            ->actingAs($creator, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson("/api/v1/creator/videos/{$video->id}/edits", [
                'project' => [
                    'version' => 2,
                    'project_id' => 'project-unsupported',
                    'duration' => 12,
                    'canvas' => [
                        'width' => 1080,
                        'height' => 1920,
                        'fps' => 30,
                        'background_color' => '#000000',
                    ],
                    'tracks' => [
                        [
                            'id' => 'text-1',
                            'type' => 'text',
                            'start' => 1,
                            'end' => 6,
                            'preset' => 'tiktok_caption',
                            'content' => [
                                'text' => 'Hello Kulsah',
                                'font_family' => 'Poppins',
                                'font_key' => 'Poppins',
                                'font_size' => 64,
                                'alignment' => 'center',
                                'color' => '#FFFFFF',
                                'preset' => 'tiktok_caption',
                            ],
                            'background' => [
                                'enabled' => true,
                                'color' => '#000000',
                            ],
                            'stroke' => [
                                'width' => 4,
                                'color' => '#000000',
                            ],
                            'shadow' => [
                                'x' => 6,
                                'y' => 6,
                                'color' => 'black@0.55',
                            ],
                            'transition' => [
                                'enter' => 'slide_up',
                                'exit' => 'fade_out',
                                'duration' => 0.35,
                            ],
                            'animation' => [
                                'preset' => 'fade_in',
                                'duration' => 0.35,
                            ],
                            'keyframes' => [
                                [
                                    'time' => 1,
                                    'y' => 440,
                                    'opacity' => 0,
                                ],
                                [
                                    'time' => 2,
                                    'y' => 400,
                                    'opacity' => 1,
                                ],
                            ],
                            'transform' => [
                                'x' => 540,
                                'y' => 400,
                            ],
                        ],
                    ],
                    'output' => [
                        'format' => 'mp4',
                        'quality' => 'auto',
                        'width' => 1080,
                        'height' => 1920,
                    ],
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Video edit queued successfully.')
            ->assertJsonPath('data.metadata.edit_renderer', 'ffmpeg')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.metadata.transition.enter', 'slide_up')
            ->assertJsonPath('data.metadata.render_timeline.layers.0.metadata.animation.preset', 'fade_in');

        Queue::assertPushed(RenderVideoEditsJob::class);
    }
}
