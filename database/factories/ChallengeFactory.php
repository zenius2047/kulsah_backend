<?php

namespace Database\Factories;

use App\Enums\ChallengeHostType;
use App\Enums\ChallengeJudgingStrategy;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ChallengeFactory extends Factory
{
    protected $model = Challenge::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return ['created_by_user_id' => User::factory(), 'host_type' => ChallengeHostType::Creator, 'title' => $title, 'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999), 'description' => fake()->paragraph(), 'visibility' => ChallengeVisibility::Public, 'status' => ChallengeStatus::Draft, 'judging_strategy' => ChallengeJudgingStrategy::Points, 'winner_selection_method' => 'automatic_score', 'submission_starts_at' => now()->subHour(), 'submission_ends_at' => now()->addWeek(), 'show_leaderboard' => true, 'leaderboard_mode' => 'live', 'max_entries_per_creator' => 1, 'rules_version' => 1];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => ChallengeStatus::Active, 'published_at' => now()]);
    }
}
