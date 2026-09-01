<?php

namespace App\Notifications;

use App\Models\ConversationMessageRequest;
use App\Notifications\Channels\FcmChannel;
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
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toArray($notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->messageRequest->sender?->name ?: $this->messageRequest->sender?->username ?: 'New message request',
            'body' => (string) ($this->messageRequest->intro_body ?: 'You have a new message request.'),
            'data' => $this->payload($notifiable),
        ];
    }

    public function broadcastType(): string
    {
        return 'signal.message_request.created';
    }

    private function payload(object $notifiable): array
    {
        $recipientId = (int) ($notifiable->id ?? 0);

        return [
            'schema_version' => 1,
            'notification_id' => sprintf('signal.message_request.created:%d:%d', (int) $this->messageRequest->id, $recipientId),
            'type' => $this->broadcastType(),
            'request_id' => $this->messageRequest->id,
            'sender_id' => $this->messageRequest->sender_id,
            'receiver_id' => $this->messageRequest->receiver_id,
            'conversation_id' => $this->messageRequest->conversation_id,
            'sender' => [
                'id' => $this->messageRequest->sender?->id,
                'name' => $this->messageRequest->sender?->name,
                'username' => $this->messageRequest->sender?->username,
            ],
            'intro_body' => $this->messageRequest->intro_body,
            'intro_type' => $this->messageRequest->intro_type,
            'status' => $this->messageRequest->status,
            'created_at' => optional($this->messageRequest->created_at)->toIso8601String(),
        ];
    }
}
