<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeders_create_linked_data_and_can_be_run_twice(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseCount('videos', 221);
        $this->assertSame(218, DB::table('videos')->where('status', 'ready')->count());
        $this->assertSame(201, DB::table('videos')->whereNotNull('duet_source_video_id')->count());
        $this->assertSame(200, DB::table('videos')->where('source_key', 'like', 'demo/duets/%')->count());
        $this->assertDatabaseCount('video_playlists', 17);
        $this->assertDatabaseCount('community_posts', 205);
        $this->assertSame(200, DB::table('community_posts')->where('content', 'like', 'Community dispatch %')->count());
        $this->assertDatabaseCount('kulcoin_gifts', 200);
        $this->assertDatabaseCount('community_post_gifts', 201);
        $this->assertSame(200, DB::table('kulcoin_transactions')->where('idempotency_key', 'like', 'seed-volume-community-gift-%')->count());
        $this->assertDatabaseCount('events', 6);
        $this->assertDatabaseCount('challenges', 404);
        $this->assertSame(202, DB::table('challenges')->where('mode', 'creator_battle')->count());
        $this->assertSame(200, DB::table('challenges')->where('slug', 'like', 'demo-open-challenge-%')->count());
        $this->assertSame(200, DB::table('challenges')->where('slug', 'like', 'demo-creator-battle-%')->count());
        $this->assertDatabaseCount('challenge_entries', 557);
        $this->assertDatabaseCount('creator_battle_settlements', 51);
        $this->assertDatabaseCount('oauth_clients', 3);
        $this->assertDatabaseCount('notifications', 3);

        $this->assertDatabaseHas('videos', [
            'source_key' => 'demo/coastal-dance.mp4',
            'status' => 'ready',
            'processing_status' => 'ready',
        ]);
        $this->assertDatabaseHas('challenges', [
            'slug' => 'freestyle-face-off',
            'mode' => 'creator_battle',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('challenges', [
            'slug' => 'frame-vs-form-battle',
            'mode' => 'creator_battle',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('challenge_reward_transactions', [
            'status' => 'completed',
            'provider' => 'kulsah_wallet',
        ]);
        $this->assertDatabaseHas('videos', [
            'source_key' => 'demo/lifecycle/upload-in-progress.mp4',
            'status' => 'processing',
            'progress_percentage' => 42,
        ]);
        $this->assertDatabaseHas('videos', [
            'source_key' => 'demo/lifecycle/processing-failed.mp4',
            'status' => 'failed',
            'render_status' => 'failed',
        ]);
        $this->assertDatabaseHas('subscriptions', ['status' => 'expired']);
        $this->assertDatabaseHas('subscriptions', ['status' => 'cancelled']);
        $this->assertDatabaseHas('subscriptions', ['status' => 'blocked']);
        $this->assertDatabaseHas('events', [
            'title' => 'Weekend Mobility Marathon',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('events', [
            'title' => 'Small Space Design Lab',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('event_tickets', [
            'ticket_id' => 'TCK-DEMO0003-01',
            'status' => 'used',
        ]);

        $playlistOwnershipMismatches = DB::table('video_playlist_video as playlist_video')
            ->join('video_playlists as playlists', 'playlists.id', '=', 'playlist_video.video_playlist_id')
            ->join('videos', 'videos.id', '=', 'playlist_video.video_id')
            ->whereColumn('playlists.user_id', '!=', 'videos.user_id')
            ->count();
        $this->assertSame(0, $playlistOwnershipMismatches, 'A playlist contains another creator\'s video.');

        $seededBusinessTables = [
            'roles', 'users', 'user_roles', 'onboarding', 'subscription_plans', 'subscriptions',
            'subscription_actions', 'wallets', 'wallet_transactions', 'wallet_ledger_entries',
            'videos', 'video_likes', 'video_comments', 'video_comment_likes', 'video_bookmarks',
            'video_views', 'user_follows', 'video_playlists', 'video_playlist_video', 'notifications',
            'kulcoin_wallets', 'kulcoin_packages', 'kulcoin_gifts', 'kulcoin_transactions',
            'kulcoin_ledger_entries', 'community_posts', 'community_post_comments',
            'community_post_likes', 'community_post_shares', 'community_post_gifts',
            'community_post_poll_votes', 'community_post_media', 'community_post_views',
            'viewed_contents', 'events', 'event_ticket_purchases', 'event_tickets', 'oauth_clients',
            'challenges', 'challenge_collaborators', 'challenge_sponsors', 'challenge_reward_pools',
            'challenge_prizes', 'challenge_rules', 'challenge_media', 'challenge_invites',
            'challenge_entries', 'challenge_entry_eligibility_snapshots', 'challenge_judging_stages',
            'challenge_scoring_components', 'challenge_jury_members', 'challenge_jury_criteria',
            'challenge_jury_scores', 'challenge_ballots', 'challenge_ballot_choices',
            'challenge_entry_scores', 'challenge_score_snapshots', 'challenge_entry_advancements',
            'challenge_integrity_flags', 'challenge_selection_decisions', 'challenge_winners',
            'challenge_reward_allocations', 'challenge_reward_transactions', 'challenge_audit_logs',
            'creator_battle_settlements',
        ];

        foreach ($seededBusinessTables as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} has no demo records.");
        }

        $before = collect($seededBusinessTables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ]);

        $this->seed(DatabaseSeeder::class);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} was not seeded idempotently.");
        }
    }
}
