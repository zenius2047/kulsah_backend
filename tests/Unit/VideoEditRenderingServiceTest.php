<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use App\Services\VideoEditRenderingService;
use App\Services\VideoStorageService;
use Tests\TestCase;

class VideoEditRenderingServiceTest extends TestCase
{
    public function test_still_image_overlays_are_looped_in_the_ffmpeg_command(): void
    {
        $service = new VideoEditRenderingService(
            $this->createMock(VideoStorageService::class),
            $this->createMock(CloudinaryService::class),
        );

        $method = new \ReflectionMethod(VideoEditRenderingService::class, 'buildCommand');
        $method->setAccessible(true);

        $command = $method->invoke(
            $service,
            '/tmp/source.mp4',
            [
                [
                    'source' => '/tmp/overlay.png',
                    'loop' => true,
                ],
                [
                    'source' => '/tmp/overlay-video.mp4',
                    'loop' => false,
                ],
            ],
            [],
            '/tmp/output.mp4',
        );

        $this->assertContains('-loop', $command);
        $this->assertContains('1', $command);
        $this->assertContains('/tmp/overlay.png', $command);
        $this->assertContains('/tmp/overlay-video.mp4', $command);

        $loopIndex = array_search('-loop', $command, true);
        $imageInputIndex = array_search('/tmp/overlay.png', $command, true);

        $this->assertIsInt($loopIndex);
        $this->assertIsInt($imageInputIndex);
        $this->assertLessThan($imageInputIndex, $loopIndex);
    }
}
