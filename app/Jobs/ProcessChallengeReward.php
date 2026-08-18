<?php

namespace App\Jobs;

use App\Domain\Challenges\Actions\ProcessChallengeRewards;
use App\Models\ChallengeRewardAllocation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChallengeReward implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $allocationId) {}

    public function handle(ProcessChallengeRewards $action): void
    {
        if ($allocation = ChallengeRewardAllocation::find($this->allocationId)) {
            $action->execute($allocation);
        }
    }
}
