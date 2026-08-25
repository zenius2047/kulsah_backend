<?php

namespace App\Notifications;

use App\Models\ConversationMessage;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ConversationMessageNotification extends Notification
{
    public function __construct(
        public readonly ConversationMessage $message,
        public readonly User $sender,
        public readonly array $conversationSummary = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->sender->name ?: $this->sender->username,
            'body' => (string) ($this->message->body ?: 'Sent you a message.'),
            'data' => $this->payload($notifiable),
        ];
    }

    public function broadcastType(): string
    {
        return 'conversation.message.created';
    }

    private function payload(object $notifiable): array
    {
        return [
            'type' => 'conversation.message.created',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'client_message_id' => $this->message->client_message_id,
            'sender' => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'username' => $this->sender->username,
                'avatar' => $this->sender->avatar,
            ],
            'body' => $this->message->body,
            'message_type' => $this->message->type,
            'conversation' => $this->conversationSummary,
            'notified_user_id' => $notifiable->id ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
