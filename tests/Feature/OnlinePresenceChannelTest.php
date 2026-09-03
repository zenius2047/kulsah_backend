<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlinePresenceChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_join_the_online_presence_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-online',
        ]);

        $response->assertOk();

        $channelData = json_decode((string) $response->json('channel_data'), true);
        $this->assertSame((string) $user->id, (string) ($channelData['user_id'] ?? ''));
        $this->assertSame((int) $user->id, (int) ($channelData['user_info']['id'] ?? 0));
    }

    public function test_guests_cannot_join_the_online_presence_channel(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-online',
        ]);

        $this->assertContains($response->status(), [302, 401]);
    }
}
