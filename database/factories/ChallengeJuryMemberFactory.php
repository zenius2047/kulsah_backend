<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeJuryMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChallengeJuryMemberFactory extends Factory
{
    protected $model = ChallengeJuryMember::class;

    public function definition(): array
    {
        return ['challenge_id' => Challenge::factory(), 'user_id' => User::factory(), 'role' => 'judge', 'weight_bps' => 10000, 'status' => 'accepted', 'invited_at' => now(), 'accepted_at' => now()];
    }
}
