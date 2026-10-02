<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConversationReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_retried_attachment_message_is_created_once_and_read_state_is_reconciled(): void
    {
        Event::fake();
        Notification::fake();
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::query()->create([
            'created_by_user_id' => $sender->id,
            'is_group' => false,
        ]);
        ConversationParticipant::query()->insert([
            [
                'conversation_id' => $conversation->id,
                'user_id' => $sender->id,
                'unread_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'conversation_id' => $conversation->id,
                'user_id' => $recipient->id,
                'unread_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $attachment = ConversationMessageAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'kind' => 'image',
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 128,
            'disk' => 's3',
            'source_key' => 'messages/photo.jpg',
            'source_url' => 'https://cdn.example.com/photo.jpg',
            'status' => 'uploaded',
            'uploaded_at' => now(),
            'metadata' => [],
        ]);
        $payload = [
            'client_message_id' => 'client-retry-1',
            'idempotency_key' => 'client-retry-1',
            'type' => 'image',
            'attachment_ids' => [$attachment->id],
        ];

        $first = $this->actingAs($sender, 'sanctum')
            ->postJson("/api/v1/general/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated();
        $retry = $this->actingAs($sender, 'sanctum')
            ->postJson("/api/v1/general/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated();

        $messageId = $first->json('data.id');
        $this->assertSame($messageId, $retry->json('data.id'));
        $this->assertSame(1, ConversationMessage::query()->where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('conversation_message_attachments', [
            'id' => $attachment->id,
            'conversation_message_id' => $messageId,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'unread_count' => 1,
        ]);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/general/conversations/{$conversation->id}/read", [
                'last_read_message_id' => $messageId,
            ])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $messageId)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseHas('conversation_messages', [
            'id' => $messageId,
            'delivery_status' => 'read',
        ]);
    }
}
