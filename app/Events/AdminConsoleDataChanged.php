<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class AdminConsoleDataChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $resource,
        public readonly string $action,
        public readonly string $actorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin-console')];
    }

    public function broadcastAs(): string
    {
        return 'console.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'resource' => $this->resource,
            'action' => $this->action,
            'actorId' => $this->actorId,
            'updatedAt' => now()->toIso8601String(),
        ];
    }
}
