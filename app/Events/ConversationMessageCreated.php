<?php

namespace App\Events;

use App\Http\Resources\ConversationMessageResource;
use App\Models\ConversationMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ConversationMessage $message,
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly ?int $userId = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'user_id' => $this->userId,
            'message' => (new ConversationMessageResource($this->message))->toArray(request()),
        ];
    }
}


