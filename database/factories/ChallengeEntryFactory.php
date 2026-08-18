<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeEntry;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengeEntryFactory extends Factory
{
    protected $model = ChallengeEntry::class;

    public function definition(): array
    {
        return ['challenge_id' => Challenge::factory(), 'creator_id' => User::factory(), 'video_id' => Video::factory(), 'submission_number' => 1, 'status' => 'approved', 'moderation_status' => 'approved', 'eligibility_status' => 'eligible', 'submitted_at' => now(), 'approved_at' => now(), 'current_score' => 0];
    }
}
