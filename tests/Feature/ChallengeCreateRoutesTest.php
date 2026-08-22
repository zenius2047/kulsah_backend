<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeCreateRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_store_endpoint_creates_a_scheduled_challenge_for_future_windows_and_draft_endpoint_keeps_draft_status(): void
    {
        $creator = User::factory()->create();
        $scheduledPayload = $this->payload('Scheduled creator challenge', now()->addDay(), now()->addDays(8));
        $draftPayload = $this->payload('Draft creator challenge', now()->addDay(), now()->addDays(8));

        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $scheduledPayload)
            ->assertCreated();

        $this->actingAs($creator)->withoutMiddleware()->postJson('/api/v1/creator/challenges/draft', $draftPayload)
            ->assertCreated();

        $scheduledChallenge = Challenge::query()->where('title', 'Scheduled creator challenge')->firstOrFail();
        $draftChallenge = Challenge::query()->where('title', 'Draft creator challenge')->firstOrFail();

        $this->assertSame(ChallengeStatus::Scheduled, $scheduledChallenge->status);
        $this->assertSame(ChallengeStatus::Draft, $draftChallenge->status);
    }

    private function payload(string $title, \DateTimeInterface $submissionStartsAt, \DateTimeInterface $submissionEndsAt): array
    {
        return [
            'title' => $title,
            'description' => 'Create an original creator challenge.',
            'visibility' => 'public',
            'judging_strategy' => 'weighted_normalized',
            'winner_selection_method' => 'automatic_score',
            'submission_starts_at' => $submissionStartsAt->format(DATE_ATOM),
            'submission_ends_at' => $submissionEndsAt->format(DATE_ATOM),
            'max_entries_per_creator' => 1,
            'voting_configuration' => [
                'mode' => 'single_choice',
                'allow_self_voting' => false,
                'allow_vote_changes' => true,
            ],
            'prizes' => [
                [
                    'rank_from' => 1,
                    'rank_to' => 1,
                    'reward_type' => 'cash',
                    'title' => 'Winner',
                    'currency' => 'USD',
                    'amount' => '100.00',
                ],
            ],
            'scoring_components' => [
                ['type' => 'public_votes', 'weight_bps' => 5000],
                ['type' => 'reactions', 'weight_bps' => 5000],
            ],
        ];
    }
}
