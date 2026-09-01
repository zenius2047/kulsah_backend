<?php

namespace App\Jobs;

use App\Models\Challenge;
use App\Models\ChallengeIntegrityFlag;
use App\Models\VideoView;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeChallengeIntegrity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $challengeId) {}

    public function handle(): void
    {
        $challenge = Challenge::with('entries')->find($this->challengeId);
        if (! $challenge) {
            return;
        } $threshold = (int) data_get($challenge->integrity_configuration, 'maximum_views_per_user_per_entry', 50);
        foreach ($challenge->entries as $entry) {
            $suspects = VideoView::where('video_id', $entry->video_id)->selectRaw('user_id, COUNT(*) as aggregate')->groupBy('user_id')->havingRaw('COUNT(*) > ?', [$threshold])->get();
            foreach ($suspects as $suspect) {
                ChallengeIntegrityFlag::firstOrCreate(['challenge_id' => $challenge->id, 'challenge_entry_id' => $entry->id, 'user_id' => $suspect->user_id, 'type' => 'view_velocity', 'status' => 'open'], ['severity' => 'medium', 'evidence' => ['view_count' => (int) $suspect->aggregate, 'threshold' => $threshold]]);
            }
        }
    }
}
