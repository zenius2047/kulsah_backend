<?php

namespace App\Events;

use App\Models\Challenge;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChallengeStatusChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

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
        return ['challenge_id' => $this->challenge->id, 'status' => $this->challenge->status->value];
    }
}
