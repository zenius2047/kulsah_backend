<?php

namespace Tests\Feature;

use App\Contracts\LiveStreamingProviderInterface;
use App\Enums\LiveStatus;
use App\Models\KulCoinGift;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveAuthorizationService;
use App\Services\LiveBattleService;
use App\Services\LiveCohostService;
use App\Services\LiveGiftService;
use App\Services\LiveLikeService;
use App\Services\LivePresenceService;
use App\Services\LiveSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Fakes\FakeLiveStreamingProvider;
use Tests\TestCase;

class LiveStreamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(LiveStreamingProviderInterface::class, new FakeLiveStreamingProvider());
        config()->set('agora.enabled', false);
    }

    public function test_creator_can_create_start_and_end_a_live_session(): void
    {
        $creator = $this->creatorUser('live_creator_one');

        $service = app(LiveSessionService::class);
        $live = $service->create($creator, [
            'title' => 'Live demo',
            'description' => 'Testing live lifecycle',
            'visibility' => 'public',
            'chat_enabled' => true,
            'gifts_enabled' => true,
            'recording_enabled' => true,
        ]);

        $this->assertSame(LiveStatus::CREATED, $live->status);
        $this->assertNotEmpty($live->public_id);
        $this->assertNotEmpty($live->provider_channel);

        [$started, $credentials] = $service->start($live, $creator);
        $this->assertSame('broadcaster', $credentials['role']);
        $this->assertSame(LiveStatus::STARTING, $started->status);

        $live = $service->confirmLive($started);
        $this->assertSame(LiveStatus::LIVE, $live->status);

        $ended = $service->end($live, 'creator_ended');
        $this->assertSame(LiveStatus::ENDED, $ended->status);
        $this->assertSame('creator_ended', $ended->termination_reason);
    }

    public function test_leaving_a_live_persists_integer_watch_seconds(): void
    {
        $creator = $this->creatorUser('live_creator_watch_seconds');
        $viewer = User::factory()->create(['activated' => true]);
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'status' => LiveStatus::LIVE,
        ]);

        [$session] = app(LivePresenceService::class)->join($live, $viewer);
        $session->forceFill(['joined_at' => now()->subSeconds(253)->subMicroseconds(556867)])->saveQuietly();

        app(LivePresenceService::class)->leave($session->fresh());

        $this->assertDatabaseHas('live_viewer_sessions', [
            'id' => $session->id,
            'watch_seconds' => 253,
        ]);
    }
    public function test_stale_live_enters_reconnecting_before_it_is_ended(): void
    {
        $creator = $this->creatorUser('live_creator_timeout');
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'status' => LiveStatus::LIVE,
            'last_heartbeat_at' => now()->subSeconds(16),
        ]);

        config()->set('live.heartbeat_ttl_seconds', 15);
        config()->set('live.reconnect_grace_seconds', 45);

        $reconciled = app(LiveSessionService::class)->reconcileStaleLive($live);

        $this->assertTrue($reconciled);
        $this->assertSame(LiveStatus::RECONNECTING, $live->refresh()->status);
    }

    public function test_reconnecting_live_is_ended_after_grace_period(): void
    {
        $creator = $this->creatorUser('live_creator_timeout_end');
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'status' => LiveStatus::RECONNECTING,
        ]);
        $live->forceFill(['updated_at' => Carbon::now()->subSeconds(46)])->saveQuietly();

        config()->set('live.reconnect_grace_seconds', 45);

        $reconciled = app(LiveSessionService::class)->reconcileStaleLive($live->refresh());

        $this->assertTrue($reconciled);
        $this->assertDatabaseHas('live_sessions', [
            'id' => $live->id,
            'status' => LiveStatus::ENDED->value,
            'termination_reason' => 'system_timeout',
        ]);
    }
    public function test_viewer_cannot_join_subscriber_only_live_without_active_subscription(): void
    {
        $creator = $this->creatorUser('live_creator_two');
        $viewer = User::factory()->create(['activated' => true]);

        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'subscribers',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.$creator->id,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LiveAuthorizationService::class)->assertViewerCanJoin($viewer, $live);
    }

    public function test_viewer_join_is_idempotent_and_reuses_session(): void
    {
        $creator = $this->creatorUser('live_creator_three');
        $viewer = User::factory()->create(['activated' => true]);
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.$creator->id,
        ]);

        Redis::shouldReceive('sadd')->times(3)->andReturn(1);
        Redis::shouldReceive('expire')->times(3)->andReturnTrue();
        Redis::shouldReceive('scard')->times(3)->andReturn(1);

        $presence = app(LivePresenceService::class);
        [$firstSession] = $presence->join($live, $viewer);
        [$secondSession] = $presence->join($live, $viewer);

        $this->assertSame($firstSession->id, $secondSession->id);
        $this->assertDatabaseCount('live_viewer_sessions', 1);
    }

    public function test_like_service_aggregates_taps_server_side(): void
    {
        $creator = $this->creatorUser('live_creator_four');
        $viewer = User::factory()->create(['activated' => true]);
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.$creator->id,
            'likes_count' => 0,
        ]);

        Redis::shouldReceive('incrby')->once()->andReturn(12);
        Redis::shouldReceive('expire')->once()->andReturnTrue();

        $payload = app(LiveLikeService::class)->like($live, $viewer, 12);

        $this->assertSame(12, $payload['likes_count']);
        $this->assertSame(12, (int) $live->refresh()->likes_count);
    }

    public function test_live_gift_debits_kulcoin_wallet_and_updates_live_totals(): void
    {
        $creator = $this->creatorUser('live_creator_five');
        $viewer = User::factory()->create(['activated' => true]);
        $gift = KulCoinGift::create([
            'code' => 'rocket',
            'name' => 'Rocket',
            'category' => 'energy',
            'coin_cost' => 25,
            'sort_order' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);
        $wallet = app(\App\Services\KulCoinService::class)->getOrCreateUserWallet($viewer);
        $wallet->forceFill(['available_balance_kc' => 100, 'bonus_balance_kc' => 0])->save();

        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.$creator->id,
            'gifts_enabled' => true,
            'gift_value_kc' => 0,
            'gifts_count' => 0,
        ]);

        $transaction = app(LiveGiftService::class)->send($live, $viewer, $gift, 2, [
            'idempotency_key' => 'live-gift-1',
        ]);

        $this->assertSame('gift', $transaction->type);
        $this->assertSame(50, (int) $live->refresh()->gift_value_kc);
        $this->assertSame(2, (int) $live->gifts_count);
    }

    public function test_cohost_acceptance_returns_broadcaster_credentials(): void
    {
        $creator = $this->creatorUser('live_creator_six');
        $viewer = User::factory()->create(['activated' => true]);
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'live-'.$creator->id,
        ]);

        $cohostRequest = app(LiveCohostService::class)->request($live, $viewer, []);
        $result = app(LiveCohostService::class)->accept($cohostRequest, $viewer);

        $this->assertSame('active', $result['cohost']->status->value);
        $this->assertSame('broadcaster', $result['credentials']['role']);
    }

    public function test_battle_invite_accept_score_and_end_flow_persists_winner(): void
    {
        $creatorA = $this->creatorUser('battle_creator_a');
        $creatorB = $this->creatorUser('battle_creator_b');

        $liveA = LiveSession::factory()->create([
            'creator_id' => $creatorA->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'battle-'.$creatorA->id,
        ]);
        $liveB = LiveSession::factory()->create([
            'creator_id' => $creatorB->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'provider' => 'agora',
            'provider_channel' => 'battle-'.$creatorB->id,
        ]);

        $battle = app(LiveBattleService::class)->invite($liveA, $liveB, $creatorA);
        $this->assertSame('pending', $battle->status->value);

        $battle = app(LiveBattleService::class)->accept($battle, $creatorB);
        $this->assertSame('active', $battle->status->value);

        $liveA->update(['gift_value_kc' => 120]);
        $liveB->update(['gift_value_kc' => 80]);
        $battle = app(LiveBattleService::class)->score($battle);
        $this->assertSame(120, (int) $battle->creator_score);

        $battle = app(LiveBattleService::class)->end($battle);
        $this->assertSame('ended', $battle->status->value);
        $this->assertSame($creatorA->id, $battle->winner_user_id);
    }

    private function creatorUser(string $username): User
    {
        $user = User::factory()->create([
            'username' => $username,
            'name' => str_replace('_', ' ', $username),
            'activated' => true,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'creator']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}


