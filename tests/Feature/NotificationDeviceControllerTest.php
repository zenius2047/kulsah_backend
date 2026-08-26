<?php

namespace Tests\Feature;

use App\Models\NotificationDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_registration_is_sanitized_and_returns_the_device_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/notification-devices', [
            'token' => 'token-123',
            'platform' => 'android',
            'provider' => 'fcm',
            'device_name' => 'Pixel',
            'app_version' => '1.2.3',
        ]);

        $deviceId = NotificationDevice::query()->where('token', 'token-123')->value('id');

        $response->assertOk()
            ->assertJsonPath('data.notification_device_id', $deviceId)
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.provider', 'fcm')
            ->assertJsonPath('data.device_name', 'Pixel')
            ->assertJsonPath('data.app_version', '1.2.3')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('notification_devices', [
            'id' => $deviceId,
            'user_id' => $user->id,
            'token' => 'token-123',
            'platform' => 'android',
            'provider' => 'fcm',
        ]);
    }

    public function test_registering_the_same_token_reassigns_it_to_the_latest_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        NotificationDevice::query()->create([
            'user_id' => $firstUser->id,
            'token' => 'shared-token',
            'platform' => 'ios',
            'device_name' => 'iPhone',
            'provider' => 'apns',
            'app_version' => '1.0.0',
            'last_seen_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($secondUser, 'sanctum')->postJson('/api/v1/auth/notification-devices', [
            'token' => 'shared-token',
            'platform' => 'ios',
            'provider' => 'apns',
            'device_name' => 'iPhone 15',
            'app_version' => '2.0.0',
        ]);

        $deviceId = NotificationDevice::query()->where('token', 'shared-token')->value('id');

        $response->assertOk()
            ->assertJsonPath('data.notification_device_id', $deviceId)
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.provider', 'apns');

        $this->assertDatabaseCount('notification_devices', 1);
        $this->assertDatabaseHas('notification_devices', [
            'id' => $deviceId,
            'user_id' => $secondUser->id,
            'token' => 'shared-token',
            'platform' => 'ios',
            'provider' => 'apns',
            'app_version' => '2.0.0',
        ]);
    }

    public function test_invalid_platform_provider_combinations_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/notification-devices', [
                'token' => 'token-456',
                'platform' => 'android',
                'provider' => 'apns',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }
}
