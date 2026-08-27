<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotificationJob;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Mockery;
use Tests\TestCase;

class SendFcmNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_id_mismatch_removes_only_the_invalid_token_and_continues_delivery(): void
    {
        $user = User::factory()->create();

        foreach (['stale-token', 'current-token'] as $token) {
            NotificationDevice::query()->create([
                'user_id' => $user->id,
                'token' => $token,
                'platform' => 'android',
                'provider' => 'fcm',
                'last_seen_at' => now(),
            ]);
        }

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')
            ->once()
            ->ordered()
            ->andThrow(new AuthenticationError('SenderId mismatch'));
        $messaging->shouldReceive('send')
            ->once()
            ->ordered()
            ->andReturn(['name' => 'projects/test/messages/1']);

        $firebaseMessagingService = Mockery::mock(FirebaseMessagingService::class);
        $firebaseMessagingService->shouldReceive('messaging')
            ->twice()
            ->andReturn($messaging);

        $job = new SendFcmNotificationJob(
            userId: $user->id,
            notificationClass: 'TestNotification',
            title: 'New message',
            body: 'Hello',
            data: ['type' => 'conversation.message.created'],
            tokens: ['stale-token', 'current-token'],
        );

        $job->handle($firebaseMessagingService);

        $this->assertDatabaseMissing('notification_devices', ['token' => 'stale-token']);
        $this->assertDatabaseHas('notification_devices', ['token' => 'current-token']);
    }
}
