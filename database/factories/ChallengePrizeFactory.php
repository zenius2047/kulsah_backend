<?php

namespace Database\Factories;

use App\Enums\ChallengeRewardType;
use App\Models\Challenge;
use App\Models\ChallengePrize;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengePrizeFactory extends Factory
{
    protected $model = ChallengePrize::class;

    public function definition(): array
    {
        return ['challenge_id' => Challenge::factory(), 'rank_from' => 1, 'rank_to' => 1, 'reward_type' => ChallengeRewardType::Cash, 'title' => 'First prize', 'currency' => 'USD', 'amount' => '100.0000'];
    }
}
