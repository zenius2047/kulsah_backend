<?php

namespace Tests\Unit;

use App\Services\CloudinaryTransformationBuilder;
use Tests\TestCase;
use RuntimeException;

class CloudinaryTransformationBuilderTest extends TestCase
{
    public function test_it_builds_text_drawing_and_sticker_transformations(): void
    {
        $builder = new CloudinaryTransformationBuilder();

        $result = $builder->buildRenderTransformations([
            'video_id' => 123,
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
                    'type' => 'drawing',
                    'asset_url' => 'https://example.com/drawing.png',
                    'x' => 200,
                    'y' => 300,
                    'width' => 400,
                    'height' => 200,
                    'start' => 2,
                    'end' => 8,
                ],
                [
                    'type' => 'sticker',
                    'public_id' => 'stickers/fire',
                    'x' => 500,
                    'y' => 600,
                    'start' => 1,
                    'end' => 10,
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

        $this->assertStringContainsString('l_text:Arial_60:Hello%20Kulsah', $result['video_transformation']);
        $this->assertStringContainsString('l_fetch:', $result['video_transformation']);
        $this->assertStringContainsString('l_stickers:fire', $result['video_transformation']);
        $this->assertStringContainsString('e_brightness:20', $result['video_transformation']);
        $this->assertStringContainsString('so_1', $result['video_transformation']);
        $this->assertStringContainsString('w_720', $result['video_transformation']);
        $this->assertStringContainsString('h_1280', $result['video_transformation']);
    }

    public function test_it_rejects_text_layers_without_text(): void
    {
        $this->expectException(RuntimeException::class);

        $builder = new CloudinaryTransformationBuilder();

        $builder->buildRenderTransformations([
            'layers' => [
                [
                    'type' => 'text',
                    'text' => '   ',
                ],
            ],
        ]);
    }

    public function test_it_maps_human_quality_labels_to_cloudinary_values(): void
    {
        $builder = new CloudinaryTransformationBuilder();

        $result = $builder->buildRenderTransformations([
            'layers' => [
                [
                    'type' => 'drawing',
                    'asset_url' => 'https://example.com/drawing.png',
                ],
            ],
            'output' => [
                'quality' => 'high',
            ],
        ]);

        $this->assertStringContainsString('q_best', $result['video_transformation']);
    }
}
