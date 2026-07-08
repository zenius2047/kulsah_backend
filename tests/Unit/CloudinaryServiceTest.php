<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use Tests\TestCase;

class CloudinaryServiceTest extends TestCase
{
    public function testSignatureParamsIncludeContextWhenTranscodeFallsBack(): void
    {
        $service = new CloudinaryService();
        $method = new \ReflectionMethod(CloudinaryService::class, 'buildSignatureParams');
        $method->setAccessible(true);

        $params = $method->invoke($service, 'kulsah/videos', 'demo-public-id', 1700000000, true);

        $this->assertSame('kulsah/videos', $params['folder']);
        $this->assertSame('demo-public-id', $params['public_id']);
        $this->assertSame('transcode_fallback=true', $params['context']);
        $this->assertSame(1700000000, $params['timestamp']);
    }

    public function testDerivedVideoUrlUsesAutoOrientationAndOptimization(): void
    {
        config()->set('services.cloudinary.cloud_name', 'demo');

        $service = new CloudinaryService();
        $method = new \ReflectionMethod(CloudinaryService::class, 'generateDerivedVideoUrl');
        $method->setAccessible(true);

        $url = $method->invoke($service, 'videos/originals/demo-public-id');

        $this->assertSame(
            'https://res.cloudinary.com/demo/video/upload/a_auto,f_auto,q_auto/videos/originals/demo-public-id',
            $url
        );
    }
}
