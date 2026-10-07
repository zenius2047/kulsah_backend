<?php

namespace Tests\Feature;

use App\Contracts\LiveStreamingProviderInterface;
use App\Enums\LiveStatus;
use App\Events\LiveUpdated;
use App\Models\KulCoinGift;
use App\Models\LiveBattle;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CreatorLiveStartedNotification;
use App\Services\KulCoinService;
use App\Services\LiveAuthorizationService;
use App\Services\LiveBattleService;
use App\Services\LiveCohostService;
use App\Services\LiveGiftService;
use App\Services\LiveLikeService;
use App\Services\LivePresenceService;
use App\Services\LiveSessionService;
use App\Services\RealtimePresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeLiveStreamingProvider;
use Tests\TestCase;

class LiveStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'testing');
        if (PHP_OS_FAMILY === 'Windows') {
            $app['config']->set('database.connections.testing.host', '127.0.0.1');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(LiveStreamingProviderInterface::class, new FakeLiveStreamingProvider);
        config()->set('agora.enabled', false);
    }

    public function test_creator_can_create_a_live_session_with_the_minimal_payload_defaults(): void
    {
        $creator = $this->creatorUser('live_creator_payload_defaults');

        $response = $this->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson('/api/v1/creator/live', [
                'title' => 'Acoustic night',
                'category' => 'Music',
                'visibility' => 'public',
                'recording_enabled' => true,
                'chat_enabled' => true,
                'gifts_enabled' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'music')
            ->assertJsonPath('data.live_type', 'regular')
            ->assertJsonPath('data.is_battle', false)
            ->assertJsonPath('data.notify_followers', true)
            ->assertJsonPath('data.age_restricted', false)
            ->assertJsonPath('data.stream_quality', '1080p_30fps')
            ->assertJsonPath('data.orientation', 'portrait')
            ->assertJsonPath('data.moderation.profanity_filter_enabled', false)
            ->assertJsonPath('data.moderation.followers_only_chat', false)
            ->assertJsonPath('data.moderation.blocked_words', []);
    }

    public function test_creator_can_create_a_live_session_with_the_complete_setup_payload(): void
    {
        $creator = $this->creatorUser('live_creator_complete_setup');
        $scheduledAt = now()->addDay()->startOfHour();

        $response = $this->actingAs($creator, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson('/api/v1/creator/live', [
                'title' => 'Creator Q&A',
                'category' => 'talk_show',
                'live_type' => 'battle',
                'visibility' => 'subscribers',
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'notify_followers' => false,
                'recording_enabled' => true,
                'chat_enabled' => true,
                'gifts_enabled' => false,
                'age_restricted' => true,
                'stream_quality' => '1080p_60fps',
                'orientation' => 'landscape',
                'moderation' => [
                    'profanity_filter_enabled' => true,
                    'followers_only_chat' => true,
                    'slow_mode_seconds' => 10,
                    'blocked_words' => ['spam', 'scam'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'talk_show')
            ->assertJsonPath('data.live_type', 'battle')
            ->assertJsonPath('data.is_battle', true)
            ->assertJsonPath('data.visibility', 'subscribers')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.notify_followers', false)
            ->assertJsonPath('data.age_restricted', true)
            ->assertJsonPath('data.stream_quality', '1080p_60fps')
            ->assertJsonPath('data.orientation', 'landscape')
            ->assertJsonPath('data.moderation.profanity_filter_enabled', true)
            ->assertJsonPath('data.moderation.followers_only_chat', true)
            ->assertJsonPath('data.moderation.slow_mode_seconds', 10)
            ->assertJsonPath('data.moderation.blocked_words', ['spam', 'scam']);
    }

    public function test_creator_can_create_start_and_end_a_live_session(): void
    {
        $creator = $this->creatorUser('live_creator_one');

        $service = app(LiveSessionService::class);
        $live = $service->create($creator, [
            'title' => 'Sunday vibes ? let\'s chill and sing together ??',
            'description' => null,
            'category' => 'Music',
            'visibility' => 'public',
            'notify_followers' => true,
            'recording_enabled' => true,
            'chat_enabled' => true,
            'gifts_enabled' => true,
            'age_restricted' => false,
            'stream_quality' => '1080p_60fps',
            'orientation' => 'portrait',
            'moderation' => [
                'profanity_filter_enabled' => true,
                'followers_only_chat' => false,
                'slow_mode_seconds' => 10,
                'blocked_words' => [' spam ', 'spam', 'scam'],
            ],
        ]);

        $this->assertSame(LiveStatus::CREATED, $live->status);
        $this->assertNotEmpty($live->public_id);
        $this->assertNotEmpty($live->provider_channel);
        $this->assertTrue($live->notify_followers);
        $this->assertFalse($live->age_restricted);
        $this->assertSame('1080p_60fps', $live->stream_quality);
        $this->assertSame('portrait', $live->orientation);
        $this->assertTrue($live->moderation['profanity_filter_enabled']);
        $this->assertSame(['spam', 'scam'], $live->moderation['blocked_words']);

        [$started, $credentials] = $service->start($live, $creator);
        $this->assertSame('broadcaster', $credentials['role']);
        $this->assertSame(LiveStatus::STARTING, $started->status);

        $live = $service->confirmLive($started);
        $this->assertSame(LiveStatus::LIVE, $live->status);

        $ended = $service->end($live, 'creator_ended');
        $this->assertSame(LiveStatus::ENDED, $ended->status);
        $this->assertSame('creator_ended', $ended->termination_reason);
    }

    public function test_live_directory_searches_titles_descriptions_categories_and_creator_names(): void
    {
        $viewer = $this->creatorUser('live_search_viewer');
        $matchingCreator = $this->creatorUser('midnight_session_host');
        $otherCreator = $this->creatorUser('morning_session_host');

        $matching = LiveSession::factory()->create([
            'creator_id' => $matchingCreator->id,
            'title' => 'Late night studio session',
            'description' => 'Relaxed beats and listener requests.',
            'category' => 'music',
            'status' => LiveStatus::LIVE,
        ]);
        LiveSession::factory()->create([
            'creator_id' => $otherCreator->id,
            'title' => 'Morning gaming stream',
            'category' => 'gaming',
            'status' => LiveStatus::LIVE,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/general/live?search_query=night&per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->public_id);
    }

    public function test_confirming_a_live_session_notifies_active_subscribers(): void
    {
        Notification::fake();

        $creator = $this->creatorUser('live_creator_with_subscriber');
        $subscriber = User::factory()->create(['activated' => true]);

        Subscription::query()->create([
            'subscriber_id' => $subscriber->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'status' => LiveStatus::STARTING,
        ]);

        app(LiveSessionService::class)->confirmLive($live);

        Notification::assertSentTo($subscriber, CreatorLiveStartedNotification::class);
        $this->assertNotNull($live->refresh()->live_started_notifications_sent_at);
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

        $this->expectException(ValidationException::class);
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

    public function test_live_comments_are_broadcast_to_the_live_channel_in_real_time(): void
    {
        Event::fake([LiveUpdated::class]);

        $creator = $this->creatorUser('live_comment_creator');
        $viewer = User::factory()->create(['activated' => true]);
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'status' => LiveStatus::LIVE,
            'chat_enabled' => true,
            'comments_count' => 0,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->withoutMiddleware(RoleMiddleware::class)
            ->postJson("/api/v1/general/live/{$live->public_id}/comments", [
                'body' => 'Hello from the live chat.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Hello from the live chat.')
            ->assertJsonPath('data.user.id', $viewer->id);

        $this->assertDatabaseHas('live_comments', [
            'live_session_id' => $live->id,
            'user_id' => $viewer->id,
            'body' => 'Hello from the live chat.',
        ]);
        $this->assertSame(1, (int) $live->refresh()->comments_count);

        Event::assertDispatched(LiveUpdated::class, function (LiveUpdated $event) use ($live, $viewer): bool {
            return $event->type === 'chat_created'
                && $event->live->is($live)
                && data_get($event->data, 'comment.user_id') === $viewer->id
                && data_get($event->data, 'comment.body') === 'Hello from the live chat.';
        });
    }

    public function test_viewer_vote_debits_coins_updates_selected_battle_side_and_is_idempotent(): void
    {
        Event::fake([LiveUpdated::class]);
        config(['kulcoin.vote_coin_price' => 10]);
        $creator = $this->creatorUser('battle_vote_creator');
        $opponent = $this->creatorUser('battle_vote_opponent');
        $viewer = User::factory()->create(['activated' => true]);
        $wallet = app(KulCoinService::class)->getOrCreateUserWallet($viewer);
        $wallet->forceFill(['available_balance_kc' => 100, 'bonus_balance_kc' => 0])->save();
        $live = LiveSession::factory()->create([
            'creator_id' => $creator->id,
            'visibility' => 'public',
            'status' => LiveStatus::LIVE,
            'live_type' => 'battle',
        ]);
        $battle = LiveBattle::create([
            'public_id' => (string) Str::uuid(),
            'creator_live_session_id' => $live->id,
            'opponent_live_session_id' => $live->id,
            'creator_id' => $creator->id,
            'opponent_id' => $opponent->id,
            'status' => 'active',
            'creator_score' => 0,
            'opponent_score' => 0,
            'invited_by_id' => $creator->id,
            'metadata' => ['shared_stage' => true],
        ]);
        $ruleId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        DB::table('revenue_rules')->insert([
            'id' => $ruleId, 'source_key' => 'live_battle', 'scope' => json_encode(['creatorId' => $opponent->id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('revenue_rule_versions')->insert([
            'id' => $versionId, 'revenue_rule_id' => $ruleId, 'version' => 1, 'deduction_type' => 'percentage',
            'value' => 10, 'currency' => 'Kulcoin', 'payer' => 'customer', 'recipient' => 'platform',
            'remaining_recipient' => 'platform', 'minimum' => null, 'maximum' => null, 'effective_at' => now()->subMinute(),
            'status' => 'active', 'description' => 'Live battle vote surcharge.', 'created_by' => null, 'last_modified_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('revenue_rule_reviews')->insert([
            'revenue_rule_version_id' => $versionId, 'reviewed_by' => $creator->id, 'decision' => 'approved', 'created_at' => now(),
        ]);
        $payload = ['target_user_id' => $opponent->id, 'vote_count' => 3, 'idempotency_key' => 'battle-vote-test'];
        $url = "/api/v1/general/live/battles/{$battle->id}/votes";

        $this->actingAs($viewer, 'sanctum')->postJson($url, $payload)
            ->assertOk()->assertJsonPath('data.vote_count', 3)->assertJsonPath('data.target_user_id', $opponent->id);
        $this->postJson($url, $payload)->assertOk();

        $this->assertSame(3, (int) $battle->refresh()->opponent_score);
        $this->assertSame(67, (int) $wallet->refresh()->available_balance_kc);
        $vote = DB::table('kul_coin_transactions')->where('idempotency_key', 'battle-vote-test')->first();
        $this->assertSame(33, (int) $vote->coin_amount);
        $this->assertSame(3, (int) data_get(json_decode($vote->metadata, true), 'revenue_rule_calculation.customerSurcharge'));
        $this->assertDatabaseHas('live_battle_participants', ['live_battle_id' => $battle->id, 'user_id' => $opponent->id, 'score' => 3]);
        Event::assertDispatched(LiveUpdated::class, fn (LiveUpdated $event) => $event->type === 'battle_vote'
            && data_get($event->data, 'vote.target_user_id') === $opponent->id);
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
        $wallet = app(KulCoinService::class)->getOrCreateUserWallet($viewer);
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
        app(LiveCohostService::class)->accept($cohostRequest, $creator);
        $result = app(LiveCohostService::class)->accept($cohostRequest, $viewer);

        $this->assertSame('active', $result['cohost']->status);
        $this->assertSame('broadcaster', $result['credentials']['role']);
    }

    public function test_battle_invite_accept_score_and_end_flow_persists_winner(): void
    {
        Notification::fake();
        $this->mock(RealtimePresenceService::class)->shouldReceive('isOnline')->andReturn(true);
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
