<?php

namespace Tests\Unit;

use App\Services\VideoProjectNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VideoProjectNormalizerTest extends TestCase
{
    public function test_v3_project_builds_an_ordered_anchor_aware_render_plan_in_seconds(): void
    {
        $result = app(VideoProjectNormalizer::class)->normalize([
            'video_id' => 42,
            'schemaVersion' => '3.0.0',
            'metadata' => ['duration' => 20],
            'canvas' => ['width' => 1080, 'height' => 1920],
            'output' => [
                'format' => 'mp4',
                'width' => 540,
                'height' => 960,
                'videoCodec' => 'h264',
                'audioCodec' => 'aac',
                'pixelFormat' => 'yuv420p',
                'encoderPreset' => 'veryfast',
            ],
            'assets' => [[
                'id' => 'managed-raster',
                'type' => 'image',
                'storageProvider' => 'cloudinary',
                'storageKey' => 'kulsah/videos/42/assets/raster.png',
                'mimeType' => 'image/png',
            ]],
            'scenes' => [
                [
                    'id' => 'scene-later',
                    'order' => 2,
                    'timeline' => ['start' => 10, 'duration' => 5],
                    'tracks' => [],
                ],
                [
                    'id' => 'scene-first',
                    'order' => 1,
                    'timeline' => ['start' => 2, 'duration' => 12],
                    'tracks' => [
                        [
                            'id' => 'higher-layer',
                            'type' => 'image',
                            'layer' => 2,
                            'timeline' => ['start' => 3, 'duration' => 8],
                            'transform' => [
                                'position' => ['x' => 740, 'y' => 1400],
                                'size' => ['width' => 240, 'height' => 240],
                                'scale' => ['x' => 1, 'y' => 1],
                                'anchor' => ['x' => 0.5, 'y' => 0.5],
                            ],
                            'source' => ['assetId' => 'managed-raster'],
                            'fit' => 'contain',
                        ],
                        [
                            'id' => 'raster-text',
                            'type' => 'text',
                            'layer' => 1,
                            'timeline' => ['start' => 1, 'duration' => 4],
                            'transform' => [
                                'position' => ['x' => 200, 'y' => 300],
                                'size' => ['width' => 100, 'height' => 50],
                                'scale' => ['x' => 2, 'y' => 2],
                                'anchor' => ['x' => 0.5, 'y' => 0.5],
                            ],
                            'content' => ['text' => 'Editable text'],
                            'renderSource' => ['type' => 'raster', 'assetId' => 'managed-raster'],
                        ],
                    ],
                ],
            ],
            'globalAudioTracks' => [],
            'globalEffects' => [],
            'guides' => ['showSafeArea' => true],
        ], 7);

        $this->assertSame('scene-first', $result['project']['scenes'][0]['id']);
        $this->assertSame('raster-text', $result['project']['scenes'][0]['tracks'][0]['id']);

        $textLayer = $result['timeline']['layers'][0];
        $imageLayer = $result['timeline']['layers'][1];

        $this->assertSame('image', $textLayer['type']);
        $this->assertSame('text', $textLayer['source_track_type']);
        $this->assertSame(3.0, $textLayer['start']);
        $this->assertSame(7.0, $textLayer['end']);
        $this->assertSame(50, $textLayer['x']);
        $this->assertSame(125, $textLayer['y']);
        $this->assertSame(100, $textLayer['width']);
        $this->assertSame(50, $textLayer['height']);

        $this->assertSame(5.0, $imageLayer['start']);
        $this->assertSame(13.0, $imageLayer['end']);
        $this->assertSame(310, $imageLayer['x']);
        $this->assertSame(640, $imageLayer['y']);
        $this->assertSame(120, $imageLayer['width']);
        $this->assertSame(120, $imageLayer['height']);
    }

    public function test_v3_project_rejects_untrusted_url_only_assets(): void
    {
        $this->expectException(ValidationException::class);

        app(VideoProjectNormalizer::class)->normalize([
            'video_id' => 42,
            'schemaVersion' => '3.0.0',
            'assets' => [[
                'id' => 'external-image',
                'type' => 'image',
                'url' => 'https://attacker.example/overlay.png',
                'mimeType' => 'image/png',
            ]],
            'scenes' => [[
                'timeline' => ['start' => 0, 'duration' => 5],
                'tracks' => [[
                    'type' => 'image',
                    'timeline' => ['start' => 0, 'duration' => 5],
                    'source' => ['assetId' => 'external-image'],
                ]],
            ]],
        ], 7);
    }

    public function test_v3_project_rejects_unsafe_output_options(): void
    {
        $this->expectException(ValidationException::class);

        app(VideoProjectNormalizer::class)->normalize([
            'schemaVersion' => '3.0.0',
            'output' => ['videoCodec' => 'copy;rm -rf'],
        ], 7);
    }
}
