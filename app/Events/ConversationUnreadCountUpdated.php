<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUnreadCountUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly int $userId,
        public readonly int $unreadCount,
        public readonly string $eventId,
        public readonly string $occurredAt,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.unread_count';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversation->id,
            'unread_count' => $this->unreadCount,
        ];
    }
}
