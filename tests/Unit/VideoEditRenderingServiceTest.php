<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use App\Services\VideoEditRenderingService;
use App\Services\VideoStorageService;
use App\Models\Video;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    public function test_overlay_assets_are_materialized_to_local_temp_files_before_rendering(): void
    {
        $diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kulsah-video-edit-render-tests';
        File::ensureDirectoryExists($diskRoot);

        config()->set('filesystems.disks.testlocal', [
            'driver' => 'local',
            'root' => $diskRoot,
            'url' => 'http://localhost/storage',
            'visibility' => 'private',
            'throw' => false,
        ]);

        Storage::disk('testlocal')->put('videos/edit-assets/asset.png', 'overlay-bytes');

        $service = new VideoEditRenderingService(
            $this->createMock(VideoStorageService::class),
            $this->createMock(CloudinaryService::class),
        );

        $method = new \ReflectionMethod(VideoEditRenderingService::class, 'materializeOverlayInput');
        $method->setAccessible(true);

        $materialized = $method->invoke($service, [
            'type' => 'image',
            'asset_disk' => 'testlocal',
            'asset_key' => 'videos/edit-assets/asset.png',
        ]);

        $this->assertIsArray($materialized);
        $this->assertFileExists($materialized['source']);
        $this->assertSame($materialized['source'], $materialized['cleanup']);
        $this->assertSame('overlay-bytes', file_get_contents($materialized['source']));
    }

    public function test_render_timeout_scales_with_source_duration(): void
    {
        $service = new VideoEditRenderingService(
            $this->createMock(VideoStorageService::class),
            $this->createMock(CloudinaryService::class),
        );

        $video = new Video([
            'duration' => 25,
            'metadata' => [],
        ]);

        $method = new \ReflectionMethod(VideoEditRenderingService::class, 'resolveProcessTimeoutSeconds');
        $method->setAccessible(true);

        $timeout = $method->invoke($service, $video, [
            ['type' => 'text'],
            ['type' => 'image'],
        ]);

        $this->assertGreaterThan(300, $timeout);
    }
}
