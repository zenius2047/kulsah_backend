<?php

namespace App\Jobs;

use App\Domain\Challenges\Actions\FinalizeChallengeResults;
use App\Models\Challenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeChallengeResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $challengeId) {}

    public function handle(FinalizeChallengeResults $action): void
    {
        if ($challenge = Challenge::find($this->challengeId)) {
            $action->execute($challenge);
        }
    }
}
