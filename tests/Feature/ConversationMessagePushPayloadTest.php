<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotificationJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\ConversationMessageNotification;
use App\Services\RealtimePresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use Tests\TestCase;

class ConversationMessagePushPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_message_push_uses_the_standard_payload_shape(): void
    {
        config(['broadcasting.default' => 'null']);
        Bus::fake();

        $presenceService = Mockery::mock(RealtimePresenceService::class);
        $presenceService->shouldReceive('shouldSuppressPush')->once()->andReturn(false);
        $this->app->instance(RealtimePresenceService::class, $presenceService);

        $sender = User::factory()->create([
            'name' => 'Sender One',
            'username' => 'sender.one',
        ]);
        $receiver = User::factory()->create([
            'name' => 'Receiver One',
            'username' => 'receiver.one',
        ]);

        NotificationDevice::query()->create([
            'user_id' => $receiver->id,
            'token' => 'receiver-token',
            'platform' => 'android',
            'device_name' => 'Pixel',
            'provider' => 'fcm',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        NotificationDevice::query()->create([
            'user_id' => $receiver->id,
            'token' => 'receiver-apns-token',
            'platform' => 'ios',
            'device_name' => 'iPhone',
            'provider' => 'apns',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        NotificationDevice::query()->create([
            'user_id' => $receiver->id,
            'token' => 'receiver-old-fcm-token',
            'platform' => 'android',
            'device_name' => 'Old Pixel',
            'provider' => 'fcm',
            'app_version' => '0.9.0',
            'last_seen_at' => now()->subDay(),
        ]);

        $conversation = Conversation::query()->create([
            'created_by_user_id' => $sender->id,
            'conversation_key' => 'conversation:'.Str::uuid(),
            'is_group' => false,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'role' => 'participant',
            'unread_count' => 0,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $receiver->id,
            'role' => 'participant',
            'unread_count' => 3,
        ]);

        $message = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'client_message_id' => 'client-message-1',
            'type' => 'text',
            'body' => 'Hello there',
            'metadata' => [],
            'delivery_status' => 'sent',
        ]);

        app(FcmChannel::class)->send($receiver, new ConversationMessageNotification(
            message: $message,
            sender: $sender,
            recipientUnreadCounts: [$receiver->id => 3],
        ));

        Bus::assertDispatched(SendFcmNotificationJob::class, function (SendFcmNotificationJob $job) use ($receiver, $message): bool {
            CloudMessage::new()->withData($this->stringifyData($job->data));

            return $job->userId === $receiver->id
                && $job->notificationClass === ConversationMessageNotification::class
                && $job->tokens === ['receiver-token', 'receiver-old-fcm-token']
                && $job->data['schema_version'] === 1
                && $job->data['type'] === 'conversation.message.created'
                && $job->data['notification_id'] === 'conversation.message.created:'.$message->id.':'.$receiver->id
                && $job->data['unread_count'] === 3
                && $job->data['content_type'] === 'text'
                && ! array_key_exists('message_type', $job->data)
                && ! array_key_exists('conversation', $job->data);
        });
    }

    private function stringifyData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(function ($value, $key): array {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                return [(string) $key => (string) ($value ?? '')];
            })
            ->all();
    }
}
