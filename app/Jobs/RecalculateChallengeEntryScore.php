<?php

namespace App\Jobs;

use App\Domain\Challenges\Services\ChallengeScoringEngine;
use App\Models\ChallengeEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateChallengeEntryScore implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $entryId) {}

    public function handle(ChallengeScoringEngine $engine): void
    {
        if ($entry = ChallengeEntry::find($this->entryId)) {
            $engine->recalculate($entry);
        }
    }
}
