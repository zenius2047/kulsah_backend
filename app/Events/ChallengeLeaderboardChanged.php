<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChallengeLeaderboardChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(public int $challengeId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('challenges.'.$this->challengeId.'.leaderboard')];
    }

    public function broadcastAs(): string
    {
        return 'challenge.leaderboard.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'challenge_id' => $this->challengeId,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }
}
