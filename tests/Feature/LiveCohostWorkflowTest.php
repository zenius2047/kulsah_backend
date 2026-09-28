<?php

namespace Tests\Feature;

use App\Contracts\LiveStreamingProviderInterface;
use App\Events\LiveUpdated;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use App\Services\RealtimePresenceService;
use App\Services\AgoraLiveStreamingProvider;
use Tests\Fakes\FakeLiveStreamingProvider;
use Tests\TestCase;

class LiveCohostWorkflowTest extends TestCase
{
    private User $creator;
    private User $viewer;
    private User $stranger;
    private LiveSession $live;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the actual Live migrations in an isolated database, without PostgreSQL/Redis.
        config(['database.default' => 'cohost_test', 'database.connections.cohost_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'cache.default' => 'array', 'broadcasting.default' => 'null']);
        DB::purge('cohost_test');
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_08_25_000002_create_user_blocks_table.php',
            '2026_08_28_000001_create_live_tables.php',
            '2026_08_29_000001_add_live_domain_tables_and_columns.php',
            '2026_09_03_000001_add_live_creation_fields_to_live_sessions_table.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
        Event::fake([LiveUpdated::class]);
        Notification::fake();
        $this->mock(RealtimePresenceService::class)->shouldReceive('isOnline')->andReturn(true);
        app()->instance(LiveStreamingProviderInterface::class, new FakeLiveStreamingProvider());
        $this->creator = $this->user('host', 'creator');
        $this->viewer = $this->user('guest', 'fan');
        $this->stranger = $this->user('stranger', 'creator');
        $this->live = LiveSession::factory()->create(['creator_id' => $this->creator->id]);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'username' => $name, 'email' => "$name@example.test", 'password' => 'password', 'activated' => true]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));
        return $user;
    }

    private function path(string $suffix): string
    {
        return '/api/v1/general/live/'.$this->live->public_id.'/'.$suffix;
    }

    private function requestJoin(): int
    {
        return $this->actingAs($this->viewer, 'sanctum')->postJson($this->path('cohost-requests'))
            ->assertCreated()->json('data.id');
    }

    private function accept(int $id, User $actor)
    {
        return $this->actingAs($actor, 'sanctum')->postJson("/api/v1/general/live/cohost-requests/$id/accept");
    }

    public function test_viewer_requires_creator_approval_and_explicit_acceptance(): void
    {
        $id = $this->requestJoin();
        $this->accept($id, $this->viewer)->assertForbidden();
        $this->accept($id, $this->stranger)->assertForbidden();
        $this->accept($id, $this->creator)->assertOk()->assertJsonPath('data.credentials', null)->assertJsonPath('data.request.status', 'accepted');
        $this->assertDatabaseCount('live_cohosts', 0);
        $this->accept($id, $this->viewer)->assertOk()->assertJsonPath('data.credentials.role', 'broadcaster')->assertJsonPath('data.credentials.uid', $this->viewer->id);
        $this->assertDatabaseHas('live_cohosts', ['user_id' => $this->viewer->id, 'status' => 'active']);
        $this->assertDatabaseMissing('live_cohosts', ['user_id' => $this->creator->id]);
        $this->accept($id, $this->viewer)->assertOk();
        $this->assertDatabaseCount('live_cohosts', 1);
        Event::assertDispatched(LiveUpdated::class, fn ($event) => $event->type === 'cohost_requested');
    }

    public function test_invitation_only_allows_invited_viewer_to_accept(): void
    {
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/cohosts/invite';
        $this->actingAs($this->stranger, 'sanctum')->postJson($url, ['invitee_id' => $this->viewer->id])->assertForbidden();
        $id = $this->actingAs($this->creator, 'sanctum')->postJson($url, ['invitee_id' => $this->viewer->id])->assertCreated()->json('data.id');
        $this->accept($id, $this->creator)->assertForbidden();
        $this->accept($id, $this->viewer)->assertOk()->assertJsonPath('data.cohost.user_id', $this->viewer->id);
    }

    public function test_pending_duplicate_cancel_retry_and_expiry(): void
    {
        $id = $this->requestJoin();
        $this->assertSame($id, $this->requestJoin());
        $this->postJson("/api/v1/general/live/cohost-requests/$id/decline")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->accept($id, $this->creator)->assertUnprocessable();
        $this->assertSame($id, $this->requestJoin());
        $this->travel(6)->minutes();
        $this->accept($id, $this->creator)->assertUnprocessable();
        $this->actingAs($this->viewer, 'sanctum')->getJson($this->path('participants'))->assertOk()->assertJsonPath('data.requests.0.status', 'expired');
    }

    public function test_inbox_privacy_and_removal_revoke_renewal(): void
    {
        $id = $this->requestJoin();
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->path('participants'))->assertOk()->assertJsonCount(0, 'data.requests')->assertJsonCount(0, 'data.viewers');
        $this->actingAs($this->creator, 'sanctum')->getJson($this->path('participants'))->assertOk()->assertJsonPath('data.requests.0.requester.name', 'guest');
        $this->accept($id, $this->creator)->assertOk();
        $this->accept($id, $this->viewer)->assertOk();
        $this->postJson($this->path('cohosts/credentials'))->assertOk()->assertJsonPath('data.role', 'broadcaster');
        $this->actingAs($this->creator, 'sanctum')->deleteJson('/api/v1/creator/live/'.$this->live->public_id.'/cohosts/'.$this->viewer->id)->assertOk();
        $this->actingAs($this->viewer, 'sanctum')->postJson($this->path('cohosts/credentials'))->assertForbidden();
        $this->accept($id, $this->viewer)->assertUnprocessable();
        $this->assertSame($id, $this->requestJoin());
    }

    public function test_guest_can_leave_stage_and_cannot_accept_after_live_ends(): void
    {
        $id = $this->requestJoin();
        $this->accept($id, $this->creator)->assertOk();
        $this->accept($id, $this->viewer)->assertOk();
        $this->postJson($this->path('cohosts/leave'))->assertOk();
        $this->postJson($this->path('cohosts/credentials'))->assertForbidden();
        $this->requestJoin();
        $this->live->update(['status' => 'ended']);
        $this->accept($id, $this->creator)->assertUnprocessable();
    }

    public function test_other_creators_cannot_control_session_or_read_analytics(): void
    {
        $base = '/api/v1/creator/live/'.$this->live->public_id;
        $this->actingAs($this->stranger, 'sanctum')->postJson($base.'/confirm')->assertForbidden();
        $this->postJson($base.'/reconnect')->assertForbidden();
        $this->getJson($base.'/analytics')->assertForbidden();
    }

    public function test_creator_can_read_inbox_for_own_subscriber_only_live(): void
    {
        $this->live->update(['visibility' => 'subscribers']);
        $this->actingAs($this->creator, 'sanctum')->getJson($this->path('participants'))->assertOk();
    }

    public function test_agora_removal_is_scoped_to_guest_and_channel_and_can_be_restored(): void
    {
        config(['agora.app_id' => 'test-app', 'agora.customer_id' => 'test-customer', 'agora.customer_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['api.agora.io/dev/v1/kicking-rule' => Http::response(['status' => 'success', 'id' => 123])]);
        $provider = new AgoraLiveStreamingProvider();
        $this->assertSame(123, $provider->revokePublishing($this->live, $this->viewer));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['cname'] === $this->live->provider_channel && $request['uid'] === $this->viewer->id
            && $request['privileges'] === ['publish_audio', 'publish_video']);
        $provider->restorePublishing(123);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request['id'] === 123);
    }

    public function test_failed_provider_removal_does_not_falsely_mark_guest_removed(): void
    {
        $id = $this->requestJoin();
        $this->accept($id, $this->creator)->assertOk();
        $this->accept($id, $this->viewer)->assertOk();
        config(['agora.app_id' => 'test-app', 'agora.customer_id' => 'test-customer', 'agora.customer_secret' => 'test-secret']);
        Http::fake(['api.agora.io/dev/v1/kicking-rule' => Http::response([], 503)]);
        app()->instance(LiveStreamingProviderInterface::class, new AgoraLiveStreamingProvider());
        $this->actingAs($this->creator, 'sanctum')->deleteJson('/api/v1/creator/live/'.$this->live->public_id.'/cohosts/'.$this->viewer->id)->assertStatus(500);
        $this->assertDatabaseHas('live_cohosts', ['user_id' => $this->viewer->id, 'status' => 'active']);
    }

    public function test_battle_routes_reject_self_invitation_and_unrelated_actors(): void
    {
        $inviteUrl = '/api/v1/creator/live/'.$this->live->public_id.'/battles/invite';
        $this->actingAs($this->creator, 'sanctum')->postJson($inviteUrl, ['opponent_live_session_public_id' => $this->live->public_id])->assertUnprocessable();
        $opponent = LiveSession::factory()->create(['creator_id' => $this->stranger->id]);
        $battle = $this->postJson($inviteUrl, ['opponent_live_session_public_id' => $opponent->public_id])->assertCreated()->json('data.id');
        $this->actingAs($this->viewer, 'sanctum')->postJson("/api/v1/general/live/battles/$battle/score")->assertUnprocessable();
        $this->postJson("/api/v1/general/live/battles/$battle/end")->assertUnprocessable();
        $opponent->update(['status' => 'ended']);
        $this->actingAs($this->stranger, 'sanctum')->postJson("/api/v1/general/live/battles/$battle/accept")->assertOk()
            ->assertJsonPath('data.metadata.shared_stage', true)->assertJsonPath('credentials.role', 'broadcaster');
    }

    public function test_cohost_capacity_is_enforced_at_acceptance(): void
    {
        config(['live.cohost_limit' => 1]);
        $id = $this->requestJoin();
        $this->accept($id, $this->creator)->assertOk();
        $this->live->cohosts()->create(['user_id' => $this->stranger->id, 'status' => 'active', 'accepted_at' => now()]);
        $this->accept($id, $this->viewer)->assertUnprocessable();
        $this->assertDatabaseCount('live_cohosts', 1);
    }

    public function test_online_creator_without_a_stream_can_be_invited_and_join_host_stage(): void
    {
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/battles/invite';
        $battle = $this->actingAs($this->creator, 'sanctum')->postJson($url, ['opponent_id' => $this->stranger->id])
            ->assertCreated()->assertJsonPath('data.opponent_id', $this->stranger->id)->json('data.id');
        $this->postJson($url, ['opponent_id' => $this->stranger->id])->assertCreated()->assertJsonPath('data.id', $battle);
        $this->assertDatabaseCount('live_sessions', 1);
        $this->assertDatabaseCount('live_cohosts', 0);
        Notification::assertSentTo($this->stranger, \App\Notifications\LiveBattleInvitationNotification::class);
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->path('participants'))->assertOk()->assertJsonPath('data.battles.0.id', $battle);
        $this->postJson("/api/v1/general/live/battles/$battle/accept")->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('credentials.role', 'broadcaster');
        $this->assertDatabaseHas('live_cohosts', ['live_session_id' => $this->live->id, 'user_id' => $this->stranger->id, 'status' => 'active']);
        $this->assertDatabaseCount('live_sessions', 1);
        $this->postJson("/api/v1/general/live/battles/$battle/score")->assertUnprocessable();
        $this->postJson("/api/v1/general/live/battles/$battle/end")->assertOk()->assertJsonPath('data.winner_user_id', null);
    }

    public function test_offline_creator_and_non_creator_cannot_be_invited(): void
    {
        $this->mock(RealtimePresenceService::class)->shouldReceive('isOnline')->andReturn(false);
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/battles/invite';
        $this->actingAs($this->creator, 'sanctum')->postJson($url, ['opponent_id' => $this->stranger->id])->assertUnprocessable();
        $this->postJson($url, ['opponent_id' => $this->viewer->id])->assertUnprocessable();
        $this->assertDatabaseCount('live_battles', 0);
    }

    public function test_battle_creator_search_matches_words_without_live_sessions_or_discovery_history(): void
    {
        $this->stranger->update(['name' => 'Jane Singer', 'username' => 'music_jane']);
        $offline = $this->user('music_jane_offline', 'creator');
        $this->mock(RealtimePresenceService::class)->shouldReceive('isOnline')
            ->andReturnUsing(fn (User $user) => $user->id !== $offline->id);
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/battle-creators';
        $this->actingAs($this->creator, 'sanctum')->getJson($url.'?search_query='.urlencode(' SINGER @music '))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->stranger->id)
            ->assertJsonPath('data.0.is_online', true)->assertJsonPath('meta.has_more', false);
        $this->getJson($url.'?search_query=unmatched')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('live_sessions', 1);
        $this->actingAs($this->stranger, 'sanctum')->getJson($url)->assertForbidden();
    }

    public function test_battle_creator_search_paginates_online_creators_only(): void
    {
        $offline = $this->user('offline_creator', 'creator');
        $next = $this->user('next_creator', 'creator');
        $this->mock(RealtimePresenceService::class)->shouldReceive('isOnline')
            ->andReturnUsing(fn (User $user) => $user->id !== $offline->id);
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/battle-creators?limit=1';
        $this->actingAs($this->creator, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->stranger->id)
            ->assertJsonPath('meta.has_more', true);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $next->id)->assertJsonPath('meta.has_more', false);
    }

    public function test_battle_stage_is_shared_by_host_participants_and_viewers_after_all_accept(): void
    {
        $second = $this->user('second_battle_creator', 'creator');
        $url = '/api/v1/creator/live/'.$this->live->public_id.'/battles/invite';
        $firstBattle = $this->actingAs($this->creator, 'sanctum')->postJson($url, ['opponent_id' => $this->stranger->id])->assertCreated()->json('data.id');
        $secondBattle = $this->postJson($url, ['opponent_id' => $second->id])->assertCreated()->json('data.id');
        $this->getJson($this->path('participants'))->assertOk()->assertJsonPath('data.battle_stage.all_accepted', false)
            ->assertJsonCount(3, 'data.battle_stage.participants');
        $this->actingAs($this->stranger, 'sanctum')->postJson("/api/v1/general/live/battles/$firstBattle/accept")->assertOk();
        $this->getJson($this->path('participants'))->assertOk()->assertJsonPath('data.battle_stage.all_accepted', false);
        $this->actingAs($second, 'sanctum')->postJson("/api/v1/general/live/battles/$secondBattle/accept")->assertOk();
        foreach ([$this->creator, $this->stranger, $second, $this->viewer] as $actor) {
            $this->actingAs($actor, 'sanctum')->getJson($this->path('participants'))->assertOk()
                ->assertJsonPath('data.battle_stage.all_accepted', true)
                ->assertJsonCount(3, 'data.battle_stage.participants')
                ->assertJsonPath('data.battle_stage.participants.0.user_id', $this->creator->id)
                ->assertJsonPath('data.battle_stage.participants.1.accepted', true)
                ->assertJsonPath('data.battle_stage.participants.2.accepted', true);
        }
    }
}
