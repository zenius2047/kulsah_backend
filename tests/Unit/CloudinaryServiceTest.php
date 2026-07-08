<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use PHPUnit\Framework\TestCase;

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
}
