<?php

namespace Tests\Feature;

use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_heartbeat_endpoint_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/heartbeat');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
            ]);
    }
}
