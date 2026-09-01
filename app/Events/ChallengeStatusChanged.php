<?php

namespace App\Events;

use App\Models\Challenge;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChallengeStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(public Challenge $challenge) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('challenges.'.$this->challenge->id)];
    }

    public function broadcastAs(): string
    {
        return 'challenge.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'challenge_id' => $this->challenge->id,
            'status' => $this->challenge->status->value,
            'updated_at' => optional($this->challenge->updated_at)?->toIso8601String(),
        ];
    }
}
