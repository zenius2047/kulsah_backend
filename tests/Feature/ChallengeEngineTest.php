<?php

namespace Tests\Feature;

use App\Domain\Challenges\Actions\CastChallengeBallot;
use App\Domain\Challenges\Actions\CreateChallenge;
use App\Domain\Challenges\Actions\SubmitChallengeEntry;
use App\Domain\Challenges\Exceptions\InvalidChallengeTransition;
use App\Domain\Challenges\Services\ChallengeLifecycleService;
use App\Domain\Challenges\Services\ChallengeScoringEngine;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeScoringComponent;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChallengeEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_uses_authenticated_creator_and_persists_nested_configuration_atomically(): void
    {
        $creator = User::factory()->create();
        $challenge = app(CreateChallenge::class)->execute($creator, $this->payload());
        $this->assertSame($creator->id, $challenge->created_by_user_id);
        $this->assertSame($creator->id, $challenge->host_user_id);
        $this->assertSame(ChallengeStatus::Draft, $challenge->status);
        $this->assertCount(1, $challenge->prizes);
        $this->assertCount(2, $challenge->scoringComponents);
        $this->assertDatabaseHas('challenge_collaborators', ['challenge_id' => $challenge->id, 'user_id' => $creator->id, 'role' => 'owner']);
    }

    public function test_creation_with_null_status_resolves_active_when_the_submission_window_is_open(): void
    {
        $creator = User::factory()->create();
        $challenge = app(CreateChallenge::class)->execute($creator, $this->payload(), null);

        $this->assertSame(ChallengeStatus::Active, $challenge->status);
    }

    public function test_lifecycle_accepts_valid_and_rejects_invalid_transitions(): void
    {
        $challenge = Challenge::factory()->create();
        $service = app(ChallengeLifecycleService::class);
        $service->transition($challenge, ChallengeStatus::PendingReview);
        $this->assertSame(ChallengeStatus::PendingReview, $challenge->refresh()->status);
        $service->transition($challenge, ChallengeStatus::Active);
        $this->assertSame(ChallengeStatus::Active, $challenge->refresh()->status);
        $this->expectException(InvalidChallengeTransition::class);
        $service->transition($challenge, ChallengeStatus::Completed);
    }

    public function test_entry_requires_owned_ready_video_and_enforces_limit_transactionally(): void
    {
        $host = User::factory()->create();
        $entrant = User::factory()->create();
        $challenge = Challenge::factory()->active()->create(['created_by_user_id' => $host->id, 'host_user_id' => $host->id]);
        $video = Video::factory()->create(['user_id' => $entrant->id]);
        $entry = app(SubmitChallengeEntry::class)->execute($challenge, $entrant, ['video_id' => $video->id]);
        $this->assertSame($entrant->id, $entry->creator_id);
        $this->assertTrue($entry->eligibilitySnapshots->first()->eligible);
        $this->expectException(ValidationException::class);
        app(SubmitChallengeEntry::class)->execute($challenge, $entrant, ['video_id' => Video::factory()->create(['user_id' => $entrant->id])->id]);
    }

    public function test_ranked_ballot_blocks_self_vote_and_accepts_arbitrary_consecutive_ranks(): void
    {
        $host = User::factory()->create();
        $voter = User::factory()->create();
        $challenge = Challenge::factory()->active()->create(['created_by_user_id' => $host->id, 'host_user_id' => $host->id, 'voting_starts_at' => now()->subMinute(), 'voting_ends_at' => now()->addHour(), 'voting_configuration' => ['mode' => 'ranked_choice', 'allow_self_voting' => false, 'allow_vote_changes' => true, 'maximum_choices' => 5, 'rank_points' => ['1' => 5, '2' => 3]]]);
        $entries = collect([1, 2])->map(function () use ($challenge) {
            $creator = User::factory()->create();

            return app(SubmitChallengeEntry::class)->execute($challenge, $creator, ['video_id' => Video::factory()->create(['user_id' => $creator->id])->id]);
        });
        $ballot = app(CastChallengeBallot::class)->execute($challenge, $voter, [['challenge_entry_id' => $entries[0]->id, 'rank' => 1], ['challenge_entry_id' => $entries[1]->id, 'rank' => 2]]);
        $this->assertCount(2, $ballot->choices);
    }

    public function test_point_scoring_reuses_video_likes_and_creates_snapshot(): void
    {
        $host = User::factory()->create();
        $entrant = User::factory()->create();
        $challenge = Challenge::factory()->active()->create(['created_by_user_id' => $host->id, 'host_user_id' => $host->id, 'judging_strategy' => 'points']);
        ChallengeScoringComponent::create(['challenge_id' => $challenge->id, 'type' => 'reactions', 'weight_bps' => 0, 'point_value' => 3, 'enabled' => true, 'rules_version' => 1]);
        $entry = app(SubmitChallengeEntry::class)->execute($challenge, $entrant, ['video_id' => Video::factory()->create(['user_id' => $entrant->id])->id]);
        $entry->video->likes()->create(['user_id' => User::factory()->create()->id]);
        app(ChallengeScoringEngine::class)->recalculate($entry);
        $this->assertSame('3.000000', $entry->refresh()->current_score);
        $this->assertDatabaseHas('challenge_score_snapshots', ['challenge_entry_id' => $entry->id, 'final_score' => 3]);
    }

    private function payload(): array
    {
        return ['title' => 'Summer Creator Challenge', 'description' => 'Create an original summer video.', 'visibility' => 'public', 'judging_strategy' => 'weighted_normalized', 'winner_selection_method' => 'automatic_score', 'submission_starts_at' => now()->subHour(), 'submission_ends_at' => now()->addWeek(), 'voting_starts_at' => now()->subMinute(), 'voting_ends_at' => now()->addWeek(), 'max_entries_per_creator' => 1, 'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false, 'allow_vote_changes' => true], 'prizes' => [['rank_from' => 1, 'rank_to' => 1, 'reward_type' => 'cash', 'title' => 'Winner', 'currency' => 'USD', 'amount' => '100.00']], 'scoring_components' => [['type' => 'public_votes', 'weight_bps' => 5000], ['type' => 'reactions', 'weight_bps' => 5000]]];
    }
}
