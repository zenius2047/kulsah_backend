<?php

namespace Tests\Feature;

use App\Models\ConversationMessageRequest;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function seedSignalRoles(): array
    {
        return [
            'fan' => Role::firstOrCreate(['name' => 'fan']),
            'creator' => Role::firstOrCreate(['name' => 'creator']),
            'admin' => Role::firstOrCreate(['name' => 'admin']),
        ];
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_mutual_fans_can_start_direct_conversation(): void
    {
        $this->seedSignalRoles();

        $sender = User::factory()->create(['username' => 'mutual_sender']);
        $receiver = User::factory()->create(['username' => 'mutual_receiver']);

        $this->attachRole($sender, 'fan');
        $this->attachRole($receiver, 'creator');

        UserFollow::query()->create([
            'follower_id' => $sender->id,
            'followed_id' => $receiver->id,
        ]);

        UserFollow::query()->create([
            'follower_id' => $receiver->id,
            'followed_id' => $sender->id,
        ]);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/general/conversations', [
                'participant_ids' => [$receiver->id],
                'initial_message' => [
                    'body' => 'Hello there',
                    'type' => 'text',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.participants.1.user_id', $receiver->id);

        $this->assertDatabaseHas('conversations', [
            'created_by_user_id' => $sender->id,
            'is_group' => false,
        ]);

        $this->assertDatabaseMissing('conversation_message_requests', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
        ]);
    }

    public function test_one_way_fan_requires_message_request(): void
    {
        $this->seedSignalRoles();

        $sender = User::factory()->create(['username' => 'fan_sender']);
        $receiver = User::factory()->create(['username' => 'fan_creator']);

        $this->attachRole($sender, 'fan');
        $this->attachRole($receiver, 'creator');

        UserFollow::query()->create([
            'follower_id' => $sender->id,
            'followed_id' => $receiver->id,
        ]);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/general/conversations', [
                'participant_ids' => [$receiver->id],
                'initial_message' => [
                    'body' => 'I would love to connect',
                    'type' => 'text',
                ],
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('conversation_message_requests', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'status' => 'pending',
        ]);
    }

    public function test_pending_request_blocks_repeat_messages(): void
    {
        $this->seedSignalRoles();

        $sender = User::factory()->create(['username' => 'pending_sender']);
        $receiver = User::factory()->create(['username' => 'pending_receiver']);

        $this->attachRole($sender, 'fan');
        $this->attachRole($receiver, 'creator');

        ConversationMessageRequest::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'status' => 'pending',
            'intro_client_message_id' => 'intro-1',
            'intro_type' => 'text',
            'intro_body' => 'Please accept my request',
            'intro_metadata' => [],
        ]);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/general/conversations', [
                'participant_ids' => [$receiver->id],
                'initial_message' => [
                    'body' => 'Another message',
                    'type' => 'text',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_id']);
    }

    public function test_active_subscription_auto_creates_fan_relationship(): void
    {
        $this->seedSignalRoles();

        $subscriber = User::factory()->create(['username' => 'subscriber_user']);
        $creator = User::factory()->create(['username' => 'creator_user']);
        $this->attachRole($subscriber, 'fan');
        $this->attachRole($creator, 'creator');

        $plan = SubscriptionPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Supporter',
            'description' => 'Monthly access',
            'price' => 10,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        Subscription::query()->create([
            'subscriber_id' => $subscriber->id,
            'creator_id' => $creator->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $subscriber->id,
            'followed_id' => $creator->id,
        ]);

        $response = $this->actingAs($subscriber, 'sanctum')
            ->postJson('/api/v1/general/conversations', [
                'participant_ids' => [$creator->id],
                'initial_message' => [
                    'body' => 'Thanks for the subscription!',
                    'type' => 'text',
                ],
            ]);

        $response->assertCreated();
    }
}
