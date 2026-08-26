<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $eventType,
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly ?int $userId = null,
        public readonly array $changes = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return $this->eventType;
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversation->id,
            'changes' => $this->changes,
        ];
    }
}
