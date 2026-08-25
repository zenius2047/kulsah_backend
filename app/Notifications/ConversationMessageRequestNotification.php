<?php

namespace App\Notifications;

use App\Models\ConversationMessageRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ConversationMessageRequestNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ConversationMessageRequest $messageRequest)
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    public function broadcastType(): string
    {
        return 'signal.message_request.created';
    }

    private function payload(): array
    {
        return [
            'type' => $this->broadcastType(),
            'request_id' => $this->messageRequest->id,
            'sender_id' => $this->messageRequest->sender_id,
            'receiver_id' => $this->messageRequest->receiver_id,
            'conversation_id' => $this->messageRequest->conversation_id,
            'intro_body' => $this->messageRequest->intro_body,
            'intro_type' => $this->messageRequest->intro_type,
            'status' => $this->messageRequest->status,
            'created_at' => optional($this->messageRequest->created_at)->toIso8601String(),
        ];
    }
}
