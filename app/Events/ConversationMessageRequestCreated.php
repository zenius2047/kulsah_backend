<?php

namespace App\Events;

use App\Models\ConversationMessageRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly ConversationMessageRequest $messageRequest)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.' . $this->messageRequest->receiver_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'signal.message_request.created';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'request' => [
                'id' => $this->messageRequest->id,
                'sender_id' => $this->messageRequest->sender_id,
                'receiver_id' => $this->messageRequest->receiver_id,
                'conversation_id' => $this->messageRequest->conversation_id,
                'intro_body' => $this->messageRequest->intro_body,
                'intro_type' => $this->messageRequest->intro_type,
                'status' => $this->messageRequest->status,
            ],
        ];
    }
}
