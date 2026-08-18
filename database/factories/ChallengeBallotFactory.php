<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeBallot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengeBallotFactory extends Factory
{
    protected $model = ChallengeBallot::class;

    public function definition(): array
    {
        return ['challenge_id' => Challenge::factory(), 'voter_id' => User::factory(), 'status' => 'submitted', 'submitted_at' => now()];
    }
}
