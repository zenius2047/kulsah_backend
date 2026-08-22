<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeInvite;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorBattleChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_battle_challenge_creation_seeds_invites_and_starts_awaiting_participants(): void
    {
        $host = User::factory()->create();
        $inviteeA = $this->creatorUser('battle_creator_a');
        $inviteeB = $this->creatorUser('battle_creator_b');
        $this->assignCreatorRole($host);

        $payload = $this->battlePayload($inviteeA->id, $inviteeB->id);

        $this->actingAs($host)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $payload)
            ->assertCreated();

        $challenge = Challenge::query()->where('title', 'Creator Battle Challenge')->firstOrFail();

        $this->assertSame('creator_battle', $challenge->mode->value);
        $this->assertSame(ChallengeStatus::AwaitingParticipants, $challenge->status);
        $this->assertSame(3, (int) $challenge->max_participants);
        $this->assertDatabaseHas('challenge_invites', [
            'challenge_id' => $challenge->id,
            'invited_user_id' => $inviteeA->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('challenge_invites', [
            'challenge_id' => $challenge->id,
            'invited_user_id' => $inviteeB->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('challenge_collaborators', [
            'challenge_id' => $challenge->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => 'accepted',
        ]);
    }

    public function test_creator_battle_invites_accept_and_participants_can_submit(): void
    {
        $host = User::factory()->create();
        $inviteeA = $this->creatorUser('battle_accept_a');
        $inviteeB = $this->creatorUser('battle_accept_b');
        $this->assignCreatorRole($host);

        $payload = $this->battlePayload($inviteeA->id, $inviteeB->id);
        $this->actingAs($host)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $payload)->assertCreated();

        $challenge = Challenge::query()->where('title', 'Creator Battle Challenge')->firstOrFail();
        $inviteA = ChallengeInvite::query()->where('challenge_id', $challenge->id)->where('invited_user_id', $inviteeA->id)->firstOrFail();
        $inviteB = ChallengeInvite::query()->where('challenge_id', $challenge->id)->where('invited_user_id', $inviteeB->id)->firstOrFail();

        $this->actingAs($inviteeA)->withoutMiddleware()->postJson("/api/v1/creator/challenges/{$challenge->id}/invites/{$inviteA->id}/accept")
            ->assertOk();

        $challenge->refresh();
        $this->assertSame(ChallengeStatus::AwaitingParticipants, $challenge->status);

        $this->actingAs($inviteeB)->withoutMiddleware()->postJson("/api/v1/creator/challenges/{$challenge->id}/invites/{$inviteB->id}/accept")
            ->assertOk();

        $challenge->refresh();
        $this->assertSame(ChallengeStatus::Active, $challenge->status);

        $video = Video::factory()->create(['user_id' => $inviteeA->id]);
        $this->actingAs($inviteeA)->withoutMiddleware()->postJson("/api/v1/creator/challenges/{$challenge->id}/entries", [
            'video_id' => $video->id,
            'caption' => 'Battle entry',
        ])->assertCreated();

        $this->assertDatabaseHas('challenge_entries', [
            'challenge_id' => $challenge->id,
            'creator_id' => $inviteeA->id,
            'video_id' => $video->id,
        ]);
    }

    public function test_creator_battle_cannot_invite_self(): void
    {
        $host = User::factory()->create();
        $this->assignCreatorRole($host);

        $this->actingAs($host)->withoutMiddleware()->postJson('/api/v1/creator/challenges', $this->battlePayload($host->id, $this->creatorUser('battle_other')->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['battle_participant_ids']);
    }

    private function battlePayload(int $inviteeAId, int $inviteeBId): array
    {
        return [
            'title' => 'Creator Battle Challenge',
            'description' => 'A creator battle between invited creators.',
            'visibility' => 'public',
            'mode' => 'creator_battle',
            'judging_strategy' => 'weighted_normalized',
            'winner_selection_method' => 'automatic_score',
            'submission_starts_at' => now()->subHour()->toIso8601String(),
            'submission_ends_at' => now()->addWeek()->toIso8601String(),
            'voting_starts_at' => now()->subMinute()->toIso8601String(),
            'voting_ends_at' => now()->addWeek()->toIso8601String(),
            'max_participants' => 3,
            'max_entries_per_creator' => 1,
            'battle_participant_ids' => [$inviteeAId, $inviteeBId],
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

    private function creatorUser(string $username): User
    {
        $user = User::factory()->create(['username' => $username, 'name' => ucfirst(str_replace('_', ' ', $username))]);
        $this->assignCreatorRole($user);

        return $user;
    }

    private function assignCreatorRole(User $user): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'creator']);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}