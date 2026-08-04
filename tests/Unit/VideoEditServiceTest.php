<?php

namespace Tests\Unit;

use App\Services\VideoEditService;
use App\Services\VideoProjectNormalizer;
use Tests\TestCase;

class VideoEditServiceTest extends TestCase
{
    public function test_image_based_layers_force_ffmpeg_rendering(): void
    {
        $service = new VideoEditService($this->createMock(VideoProjectNormalizer::class));
        $method = new \ReflectionMethod(VideoEditService::class, 'resolveRenderEngine');
        $method->setAccessible(true);

        $engine = $method->invoke($service, [
            'layers' => [
                [
                    'type' => 'image',
                    'asset_url' => 'https://example.com/image.png',
                ],
            ],
        ]);

        $this->assertSame('ffmpeg', $engine);
    }

    public function test_plain_text_layers_force_ffmpeg_rendering(): void
    {
        $service = new VideoEditService($this->createMock(VideoProjectNormalizer::class));
        $method = new \ReflectionMethod(VideoEditService::class, 'resolveRenderEngine');
        $method->setAccessible(true);

        $engine = $method->invoke($service, [
            'layers' => [
                [
                    'type' => 'text',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $this->assertSame('ffmpeg', $engine);
    }
}
