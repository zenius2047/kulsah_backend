<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotificationJob;
use App\Models\Conversation;
use App\Models\ConversationMessageRequest;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\ConversationMessageRequestNotification;
use App\Services\RealtimePresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RealtimeNotificationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_request_queues_fcm_push_for_offline_user(): void
    {
        config(['broadcasting.default' => 'null']);
        Bus::fake();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        NotificationDevice::query()->create([
            'user_id' => $receiver->id,
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'device_name' => 'Pixel',
            'provider' => 'fcm',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        $request = ConversationMessageRequest::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'status' => 'pending',
            'intro_client_message_id' => 'intro-1',
            'intro_type' => 'text',
            'intro_body' => 'Hello from Signal',
            'intro_metadata' => [],
        ])->load(['sender', 'receiver']);

        app(FcmChannel::class)->send($receiver, new ConversationMessageRequestNotification($request));

        Bus::assertDispatched(SendFcmNotificationJob::class, function (SendFcmNotificationJob $job) use ($receiver): bool {
            return $job->userId === $receiver->id
                && $job->notificationClass === ConversationMessageRequestNotification::class
                && $job->tokens === ['fcm-token-1'];
        });
    }

    public function test_message_request_skips_fcm_when_user_is_active_in_app(): void
    {
        config(['broadcasting.default' => 'null']);
        Bus::fake();

        $presenceService = Mockery::mock(RealtimePresenceService::class);
        $presenceService->shouldReceive('shouldSuppressPush')->once()->andReturn(true);
        $this->app->instance(RealtimePresenceService::class, $presenceService);

        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $conversation = Conversation::query()->create([
            'created_by_user_id' => $sender->id,
            'conversation_key' => 'conversation:'.Str::uuid(),
            'is_group' => false,
        ]);

        NotificationDevice::query()->create([
            'user_id' => $receiver->id,
            'token' => 'fcm-token-2',
            'platform' => 'ios',
            'device_name' => 'iPhone',
            'provider' => 'fcm',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        $request = ConversationMessageRequest::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
            'intro_client_message_id' => 'intro-2',
            'intro_type' => 'text',
            'intro_body' => 'Hello again',
            'intro_metadata' => [],
        ])->load(['sender', 'receiver']);

        app(FcmChannel::class)->send($receiver, new ConversationMessageRequestNotification($request));

        Bus::assertNotDispatched(SendFcmNotificationJob::class);
    }
}
