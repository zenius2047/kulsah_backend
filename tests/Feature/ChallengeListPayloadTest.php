<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeListPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_index_returns_compact_list_payload(): void
    {
        $creator = User::factory()->create([
            'name' => 'Ada Creator',
            'username' => 'ada_creator',
            'avatar' => 'https://example.com/avatar.png',
        ]);

        $challenge = Challenge::factory()->active()->create([
            'created_by_user_id' => $creator->id,
            'host_user_id' => $creator->id,
            'title' => 'Dance Challenge',
            'description' => 'Create your best dance clip.',
            'status' => ChallengeStatus::Active,
            'submission_ends_at' => now()->addDays(5),
            'metadata' => [
                'category' => 'dance',
                'is_new' => true,
            ],
        ]);

        ChallengePrize::create([
            'challenge_id' => $challenge->id,
            'rank_from' => 1,
            'rank_to' => 1,
            'reward_type' => 'cash',
            'title' => 'Winner',
            'currency' => 'USD',
            'amount' => '250.00',
        ]);

        $video = Video::factory()->create([
            'user_id' => $creator->id,
            'poster_url' => 'https://example.com/challenge-cover.jpg',
        ]);

        ChallengeMedia::create([
            'challenge_id' => $challenge->id,
            'video_id' => $video->id,
            'role' => 'cover',
            'sort_order' => 0,
            'metadata' => ['cover_url' => 'https://example.com/challenge-cover.jpg'],
        ]);

        $response = $this->actingAs($creator)->withoutMiddleware()->getJson('/api/v1/challenges');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $challenge->id)
            ->assertJsonPath('data.0.creatorId', $creator->id)
            ->assertJsonPath('data.0.creatorName', 'Ada Creator')
            ->assertJsonPath('data.0.avatar', 'https://example.com/avatar.png')
            ->assertJsonPath('data.0.category', 'dance')
            ->assertJsonPath('data.0.title', 'Dance Challenge')
            ->assertJsonPath('data.0.reward', '250 USD')
            ->assertJsonPath('data.0.image', 'https://example.com/challenge-cover.jpg')
            ->assertJsonPath('data.0.isNew', true);
    }
}
