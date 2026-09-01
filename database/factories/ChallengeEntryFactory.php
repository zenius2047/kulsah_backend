<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengeEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory(),
            'creator_id' => User::factory(),
            'video_id' => Video::factory(),
            'submission_number' => 1,
            'status' => 'active',
            'eligibility_status' => 'eligible',
            'submitted_at' => now(),
            'current_score' => 0,
        ];
    }
}
