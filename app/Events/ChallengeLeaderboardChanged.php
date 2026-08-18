<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ChallengeLeaderboardChanged implements ShouldBroadcast
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(public int $challengeId) {}

    public function broadcastOn(): array
    {
        return [new Channel('challenges.'.$this->challengeId.'.leaderboard')];
    }

    public function broadcastAs(): string
    {
        return 'challenge.leaderboard.updated';
    }
}
