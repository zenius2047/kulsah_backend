<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCollaborator;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_private_channel_authorizes_only_own_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$user->id,
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$other->id,
            ])
            ->assertForbidden();
    }

    public function test_conversation_private_channel_requires_membership(): void
    {
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $outsider = User::factory()->create();

        $conversation = Conversation::query()->create([
            'created_by_user_id' => $owner->id,
            'conversation_key' => 'conversation:test-'.uniqid('', true),
            'context_type' => null,
            'context_id' => null,
            'is_group' => false,
            'last_message_at' => null,
            'last_message_id' => null,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'unread_count' => 0,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $participant->id,
            'role' => 'participant',
            'unread_count' => 0,
        ]);

        $this->actingAs($participant, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-conversations.'.$conversation->id,
            ])
            ->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-conversations.'.$conversation->id,
            ])
            ->assertForbidden();
    }

    public function test_challenge_private_channels_require_participation(): void
    {
        $creator = User::factory()->create();
        $collaborator = User::factory()->create();
        $outsider = User::factory()->create();

        $challenge = Challenge::factory()->create([
            'created_by_user_id' => $creator->id,
            'host_user_id' => $creator->id,
        ]);

        ChallengeCollaborator::query()->create([
            'challenge_id' => $challenge->id,
            'user_id' => $collaborator->id,
            'role' => 'owner',
            'status' => 'accepted',
        ]);

        $this->actingAs($collaborator, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-challenges.'.$challenge->id,
            ])
            ->assertOk();

        $this->actingAs($collaborator, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-challenges.'.$challenge->id.'.leaderboard',
            ])
            ->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-challenges.'.$challenge->id.'.leaderboard',
            ])
            ->assertForbidden();
    }
}


