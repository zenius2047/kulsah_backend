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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationMessagePushPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_message_push_uses_the_standard_payload_shape(): void
    {
        config(['broadcasting.default' => 'null']);
        Bus::fake();

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
            return $job->userId === $receiver->id
                && $job->notificationClass === ConversationMessageNotification::class
                && $job->data['schema_version'] === 1
                && $job->data['type'] === 'conversation.message.created'
                && $job->data['notification_id'] === 'conversation.message.created:'.$message->id.':'.$receiver->id
                && $job->data['unread_count'] === 3
                && ! array_key_exists('conversation', $job->data);
        });
    }
}
