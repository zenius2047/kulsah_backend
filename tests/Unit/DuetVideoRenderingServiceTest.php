<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use App\Services\DuetVideoRenderingService;
use PHPUnit\Framework\TestCase;

class DuetVideoRenderingServiceTest extends TestCase
{
    private DuetVideoRenderingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DuetVideoRenderingService($this->createMock(CloudinaryService::class));
    }

    public function test_side_by_side_uses_contained_panels_and_mixes_both_audio_tracks(): void
    {
        $graph = $this->service->buildFilterGraph('side_by_side', true, true);

        $this->assertStringContainsString('scale=360:1280:force_original_aspect_ratio=decrease', $graph['filter']);
        $this->assertStringContainsString('hstack=inputs=2[vout]', $graph['filter']);
        $this->assertStringContainsString('amix=inputs=2:duration=shortest', $graph['filter']);
        $this->assertSame('[aout]', $graph['audio_map']);
    }

    public function test_stacked_and_picture_in_picture_use_cover_layouts(): void
    {
        $stacked = $this->service->buildFilterGraph('stacked', true, false);
        $pip = $this->service->buildFilterGraph('picture_in_picture', false, true);

        $this->assertStringContainsString('scale=720:640:force_original_aspect_ratio=increase', $stacked['filter']);
        $this->assertStringContainsString('vstack=inputs=2[vout]', $stacked['filter']);
        $this->assertStringContainsString('scale=720:1280:force_original_aspect_ratio=increase', $pip['filter']);
        $this->assertStringContainsString('overlay=442:740:eof_action=pass[vout]', $pip['filter']);
        $this->assertSame('[aout]', $pip['audio_map']);
    }

    public function test_render_can_be_silent_when_both_audio_tracks_are_disabled(): void
    {
        $graph = $this->service->buildFilterGraph('side_by_side', false, false);

        $this->assertNull($graph['audio_map']);
        $this->assertStringNotContainsString('amix=', $graph['filter']);
    }
}
