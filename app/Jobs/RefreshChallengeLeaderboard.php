<?php

namespace App\Jobs;

use App\Domain\Challenges\Services\ChallengeScoringEngine;
use App\Events\ChallengeLeaderboardChanged;
use App\Models\Challenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshChallengeLeaderboard implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $challengeId) {}

    public function handle(ChallengeScoringEngine $engine): void
    {
        $challenge = Challenge::find($this->challengeId);
        if (! $challenge) {
            return;
        }
        $challenge->entries()->where('status', 'approved')
            ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
            ->chunkById(250, fn ($entries) => $entries->each(fn ($entry) => $engine->recalculate($entry)));
        DB::transaction(function () use ($challenge): void {
            $rank = 0;
            $challenge->entries()->where('status', 'approved')
                ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
                ->orderByDesc('current_score')->orderBy('submitted_at')->orderBy('id')->lockForUpdate()->get()
                ->each(fn ($entry) => $entry->forceFill(['current_rank' => ++$rank])->save());
        });
        ChallengeLeaderboardChanged::dispatch($challenge->id);
    }
}
