<?php

namespace Database\Factories;

use App\Enums\LiveStatus;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LiveSession>
 */
class LiveSessionFactory extends Factory
{
    protected $model = LiveSession::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'creator_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'category' => fake()->randomElement(['music', 'talk', 'gaming', 'beauty']),
            'cover_url' => null,
            'visibility' => 'public',
            'scheduled_at' => null,
            'started_at' => now(),
            'ended_at' => null,
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.Str::lower(Str::random(24)),
            'chat_enabled' => true,
            'gifts_enabled' => true,
            'recording_enabled' => false,
            'current_viewers' => 0,
            'unique_viewers' => 0,
            'peak_viewers' => 0,
            'average_viewers' => 0,
            'watch_seconds_total' => 0,
            'likes_count' => 0,
            'comments_count' => 0,
            'gifts_count' => 0,
            'gift_value_kc' => 0,
            'earnings_kc' => 0,
        ];
    }
}
