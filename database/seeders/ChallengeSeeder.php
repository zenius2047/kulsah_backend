<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeBallot;
use App\Models\ChallengeBallotChoice;
use App\Models\ChallengeCollaborator;
use App\Models\ChallengeEntry;
use App\Models\ChallengeEntryEligibilitySnapshot;
use App\Models\ChallengeEntryScore;
use App\Models\ChallengeIntegrityFlag;
use App\Models\ChallengeInvite;
use App\Models\ChallengeJudgingStage;
use App\Models\ChallengeJuryCriterion;
use App\Models\ChallengeJuryMember;
use App\Models\ChallengeJuryScore;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use App\Models\ChallengeRewardAllocation;
use App\Models\ChallengeRewardPool;
use App\Models\ChallengeRewardTransaction;
use App\Models\ChallengeRule;
use App\Models\ChallengeScoreSnapshot;
use App\Models\ChallengeScoringComponent;
use App\Models\ChallengeSelectionDecision;
use App\Models\ChallengeSponsor;
use App\Models\ChallengeWinner;
use App\Models\CreatorBattleSettlement;
use App\Models\User;
use App\Models\Video;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'admin', 'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $videos = Video::query()->where('source_key', 'like', 'demo/%')->get()->keyBy(function (Video $video): string {
                return pathinfo((string) $video->source_key, PATHINFO_FILENAME);
            });

            $this->seedOpenChallenge($users, $videos);
            $this->seedActiveCreatorBattle($users, $videos);
            $this->seedCompletedCreatorBattle($users, $videos);
            $this->seedScheduledChallenge($users, $videos);
        });
    }

    private function seedOpenChallenge($users, $videos): void
    {
        $host = $users->get('creator');
        $challenge = $this->challenge('coastal-moves-30', [
            'created_by_user_id' => $host->id,
            'host_type' => 'creator',
            'host_user_id' => $host->id,
            'title' => 'Coastal Moves 30',
            'description' => 'Create a joyful 30-second dance inspired by the coast and your own city.',
            'instructions' => 'Use #CoastalMoves, keep the clip under 30 seconds, and make sure your full movement is visible.',
            'hashtag' => '#CoastalMoves',
            'visibility' => 'public',
            'mode' => 'open',
            'status' => 'active',
            'judging_strategy' => 'weighted_normalized',
            'winner_selection_method' => 'automatic_score',
            'registration_starts_at' => now()->subDays(12),
            'registration_ends_at' => now()->addDays(8),
            'submission_starts_at' => now()->subDays(5),
            'submission_ends_at' => now()->addDays(9),
            'voting_starts_at' => now()->subDay(),
            'voting_ends_at' => now()->addDays(10),
            'judging_starts_at' => now()->addDays(10),
            'judging_ends_at' => now()->addDays(12),
            'results_publish_at' => now()->addDays(13),
            'show_leaderboard' => true,
            'leaderboard_mode' => 'live',
            'max_participants' => 250,
            'max_entries_per_creator' => 1,
            'official_sound_id' => $videos->get('challenge-instructions')->id,
            'moderation_status' => 'approved',
            'rules_version' => 1,
            'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false, 'allow_vote_changes' => true],
            'integrity_configuration' => ['review_before_finalization' => true, 'view_velocity_threshold' => 100],
            'metadata' => ['seeded' => true, 'category' => 'dance', 'is_new' => true, 'reward' => 'GHS 500'],
            'published_at' => now()->subDays(6),
        ]);

        $this->collaborator($challenge, $host, 'owner', $host);
        $this->collaborator($challenge, $users->get('admin'), 'moderator', $host);
        $sponsor = $this->sponsor($challenge, 'Coastline Arts Collective', 'community_partner', 'https://example.com/coastline-arts');
        $pool = $this->rewardPool($challenge, $sponsor, 'GHS', 750, 750, 750, 0, 'funded');
        $this->prize($challenge, $pool, 1, 1, 'cash', 'Coastal Moves Champion', 'GHS', 500);
        $this->prize($challenge, $pool, 2, 3, 'feature', 'Finalist Feature', null, null, 2);
        $this->rule($challenge, 'entry', 'duration_seconds', '<=', [30]);
        $this->rule($challenge, 'entry', 'required_hashtag', '=', ['#CoastalMoves']);
        $this->media($challenge, $videos->get('challenge-instructions'), 'challenge_video', 0);
        $this->media($challenge, $videos->get('challenge-instructions'), 'cover', 1);
        $this->stage($challenge, 1, 'Community Vote', 'public_voting', now()->subDay(), now()->addDays(10));
        $this->stage($challenge, 2, 'Final Review', 'jury', now()->addDays(10), now()->addDays(12));
        $voteComponent = $this->component($challenge, 'public_votes', 6000, 'max_ratio');
        $reactionComponent = $this->component($challenge, 'reactions', 4000, 'max_ratio');
        $juryMember = $this->juryMember($challenge, $users->get('admin'), 'head_judge');
        $originality = $this->criterion($challenge, 'Originality', 'A distinct idea and personal interpretation.', 6000, 1);
        $execution = $this->criterion($challenge, 'Execution', 'Timing, framing, and movement quality.', 4000, 2);

        $entries = collect();
        $entries->put('zuri', $this->entry($challenge, $users->get('zuri.moves'), $videos->get('coastal-dance'), 1, 89.4, 1));
        $entries->put('ama', $this->entry($challenge, $host, $videos->get('coastal-style'), 1, 83.2, 2));
        $entries->put('tunde', $this->entry($challenge, $users->get('tunde.creates'), $videos->get('dog-reaction'), 1, 76.8, 3));
        foreach ($entries as $entry) {
            $this->eligibility($entry, ['duration_seconds' => true, 'required_hashtag' => true]);
        }

        $this->entryScore($challenge, $entries->get('zuri'), $voteComponent, 142, 100, 60);
        $this->entryScore($challenge, $entries->get('zuri'), $reactionComponent, 198, 73.5, 29.4);
        $this->entryScore($challenge, $entries->get('ama'), $voteComponent, 121, 85.2, 51.12);
        $this->entryScore($challenge, $entries->get('ama'), $reactionComponent, 216, 80.2, 32.08);
        $this->entryScore($challenge, $entries->get('tunde'), $voteComponent, 98, 69.0, 41.4);
        $this->entryScore($challenge, $entries->get('tunde'), $reactionComponent, 239, 88.5, 35.4);
        foreach ($entries as $key => $entry) {
            $this->scoreSnapshot($challenge, $entry, (float) $entry->current_score, (int) $entry->current_rank, ['entry' => $key]);
        }

        $this->juryScore($challenge, $entries->get('zuri'), $juryMember, $originality, 92, 'Fresh interpretation and excellent use of place.');
        $this->juryScore($challenge, $entries->get('zuri'), $juryMember, $execution, 88, 'Strong timing and confident framing.');
        $this->ballot($challenge, $users->get('fan'), $entries->get('zuri'));
        $this->ballot($challenge, $users->get('fans'), $entries->get('ama'));
        $this->audit($challenge, $host, 'challenge_published', ['status' => 'draft'], ['status' => 'active']);
    }

    private function seedActiveCreatorBattle($users, $videos): void
    {
        $host = $users->get('naledi.fit');
        $opponent = $users->get('tunde.creates');
        $challenge = $this->challenge('freestyle-face-off', [
            'created_by_user_id' => $host->id,
            'host_type' => 'creator',
            'host_user_id' => $host->id,
            'title' => 'Freestyle Face-Off',
            'description' => 'Naledi and Tunde turn the same prompt into two completely different freestyle clips.',
            'instructions' => 'Watch both entries, then cast one KulCoin vote for the creator who best transforms the prompt.',
            'hashtag' => '#FreestyleFaceOff',
            'visibility' => 'public',
            'mode' => 'creator_battle',
            'status' => 'active',
            'judging_strategy' => 'weighted_normalized',
            'winner_selection_method' => 'automatic_score',
            'submission_starts_at' => now()->subDays(4),
            'submission_ends_at' => now()->addDays(3),
            'voting_starts_at' => now()->subDays(2),
            'voting_ends_at' => now()->addDays(4),
            'results_publish_at' => now()->addDays(5),
            'show_leaderboard' => true,
            'leaderboard_mode' => 'live',
            'max_participants' => 2,
            'max_entries_per_creator' => 1,
            'official_sound_id' => $videos->get('battle-instructions')->id,
            'moderation_status' => 'approved',
            'rules_version' => 1,
            'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false, 'allow_vote_changes' => true, 'vote_coin_price' => 10],
            'integrity_configuration' => ['review_before_finalization' => true],
            'metadata' => ['seeded' => true, 'category' => 'creator_battle', 'is_new' => true, 'reward' => 'USD 250'],
            'published_at' => now()->subDays(4),
        ]);

        $this->collaborator($challenge, $host, 'owner', $host);
        $this->collaborator($challenge, $opponent, 'participant', $host);
        $this->invite($challenge, $opponent, $host, 'accepted');
        $sponsor = $this->sponsor($challenge, 'Kulsah Creator Fund', 'platform', 'https://kulsah.com');
        $pool = $this->rewardPool($challenge, $sponsor, 'USD', 250, 250, 250, 0, 'funded');
        $this->prize($challenge, $pool, 1, 1, 'cash', 'Battle Champion', 'USD', 250);
        $this->rule($challenge, 'participant', 'invited_creator_only', '=', [true]);
        $this->rule($challenge, 'entry', 'max_entries', '<=', [1]);
        $this->media($challenge, $videos->get('battle-instructions'), 'challenge_video', 0);
        $this->stage($challenge, 1, 'Community Battle Vote', 'public_voting', now()->subDays(2), now()->addDays(4));
        $voteComponent = $this->component($challenge, 'public_votes', 7000, 'max_ratio');
        $reactionComponent = $this->component($challenge, 'reactions', 3000, 'max_ratio');

        $nalediEntry = $this->entry($challenge, $host, $videos->get('ski-battle'), 1, 81.6, 1);
        $tundeEntry = $this->entry($challenge, $opponent, $videos->get('dog-remix'), 1, 78.4, 2);
        $this->eligibility($nalediEntry, ['invited_creator_only' => true, 'max_entries' => true]);
        $this->eligibility($tundeEntry, ['invited_creator_only' => true, 'max_entries' => true]);
        $this->entryScore($challenge, $nalediEntry, $voteComponent, 186, 100, 70);
        $this->entryScore($challenge, $nalediEntry, $reactionComponent, 204, 38.67, 11.6);
        $this->entryScore($challenge, $tundeEntry, $voteComponent, 174, 93.55, 65.485);
        $this->entryScore($challenge, $tundeEntry, $reactionComponent, 238, 43.05, 12.915);
        $this->scoreSnapshot($challenge, $nalediEntry, 81.6, 1, ['votes' => 186, 'reactions' => 204]);
        $this->scoreSnapshot($challenge, $tundeEntry, 78.4, 2, ['votes' => 174, 'reactions' => 238]);
        $this->ballot($challenge, $users->get('fan'), $nalediEntry);
        $this->ballot($challenge, $users->get('fans'), $tundeEntry);
        $this->audit($challenge, $host, 'battle_started', ['status' => 'awaiting_participants'], ['status' => 'active']);
    }

    private function seedCompletedCreatorBattle($users, $videos): void
    {
        $host = $users->get('kwame.frames');
        $opponent = $users->get('amina.designs');
        $challenge = $this->challenge('frame-vs-form-battle', [
            'created_by_user_id' => $host->id,
            'host_type' => 'creator',
            'host_user_id' => $host->id,
            'title' => 'Frame vs Form Creator Battle',
            'description' => 'One prompt, two visual storytellers: a travel filmmaker and an interior designer.',
            'instructions' => 'Create a short story about space, then let the community and jury decide.',
            'hashtag' => '#FrameVsForm',
            'visibility' => 'public',
            'mode' => 'creator_battle',
            'status' => 'completed',
            'judging_strategy' => 'weighted_normalized',
            'winner_selection_method' => 'automatic_score',
            'submission_starts_at' => now()->subDays(24),
            'submission_ends_at' => now()->subDays(18),
            'voting_starts_at' => now()->subDays(18),
            'voting_ends_at' => now()->subDays(12),
            'judging_starts_at' => now()->subDays(12),
            'judging_ends_at' => now()->subDays(10),
            'results_publish_at' => now()->subDays(9),
            'show_leaderboard' => true,
            'leaderboard_mode' => 'final',
            'max_participants' => 2,
            'max_entries_per_creator' => 1,
            'official_sound_id' => $videos->get('ship-travel')->id,
            'moderation_status' => 'approved',
            'rules_version' => 1,
            'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false, 'vote_coin_price' => 10],
            'integrity_configuration' => ['review_before_finalization' => true],
            'metadata' => ['seeded' => true, 'category' => 'storytelling', 'is_new' => false, 'reward' => 'USD 120'],
            'published_at' => now()->subDays(25),
            'finalized_at' => now()->subDays(9),
        ]);

        $this->collaborator($challenge, $host, 'owner', $host);
        $this->collaborator($challenge, $opponent, 'participant', $host);
        $this->invite($challenge, $opponent, $host, 'accepted', now()->subDays(23));
        $sponsor = $this->sponsor($challenge, 'Kulsah Visual Stories', 'brand', 'https://kulsah.com');
        $pool = $this->rewardPool($challenge, $sponsor, 'USD', 120, 120, 0, 120, 'distributed');
        $prize = $this->prize($challenge, $pool, 1, 1, 'cash', 'Frame vs Form Champion', 'USD', 120);
        $this->rule($challenge, 'participant', 'invited_creator_only', '=', [true]);
        $this->rule($challenge, 'entry', 'duration_seconds', '<=', [30]);
        $this->media($challenge, $videos->get('ship-travel'), 'challenge_video', 0);
        $publicStage = $this->stage($challenge, 1, 'Community Vote', 'public_voting', now()->subDays(18), now()->subDays(12));
        $juryStage = $this->stage($challenge, 2, 'Jury Review', 'jury', now()->subDays(12), now()->subDays(10));
        $voteComponent = $this->component($challenge, 'public_votes', 5000, 'max_ratio');
        $juryComponent = $this->component($challenge, 'jury', 5000, 'jury_average');
        $juryMember = $this->juryMember($challenge, $users->get('admin'), 'head_judge', now()->subDays(15));
        $story = $this->criterion($challenge, 'Story', 'Clarity and emotional impact of the visual story.', 5000, 1);
        $craft = $this->criterion($challenge, 'Craft', 'Framing, pacing, colour, and finish.', 5000, 2);

        $kwameEntry = $this->entry($challenge, $host, $videos->get('ship-story'), 1, 92.75, 1, now()->subDays(20));
        $aminaEntry = $this->entry($challenge, $opponent, $videos->get('bathroom-tips'), 1, 88.10, 2, now()->subDays(19));
        $this->eligibility($kwameEntry, ['invited_creator_only' => true, 'duration_seconds' => true], now()->subDays(20));
        $this->eligibility($aminaEntry, ['invited_creator_only' => true, 'duration_seconds' => true], now()->subDays(19));
        $this->entryScore($challenge, $kwameEntry, $voteComponent, 420, 100, 50, now()->subDays(10));
        $this->entryScore($challenge, $kwameEntry, $juryComponent, 95.5, 85.5, 42.75, now()->subDays(10));
        $this->entryScore($challenge, $aminaEntry, $voteComponent, 386, 91.90, 45.95, now()->subDays(10));
        $this->entryScore($challenge, $aminaEntry, $juryComponent, 89.5, 84.3, 42.15, now()->subDays(10));
        $this->juryScore($challenge, $kwameEntry, $juryMember, $story, 97, 'A complete story with a memorable emotional turn.', now()->subDays(11));
        $this->juryScore($challenge, $kwameEntry, $juryMember, $craft, 94, 'Excellent pacing and confident framing.', now()->subDays(11));
        $this->juryScore($challenge, $aminaEntry, $juryMember, $story, 88, 'A clear idea with useful detail.', now()->subDays(11));
        $this->juryScore($challenge, $aminaEntry, $juryMember, $craft, 91, 'Polished visual choices and strong finish.', now()->subDays(11));
        $this->scoreSnapshot($challenge, $kwameEntry, 92.75, 1, ['public_votes' => 50, 'jury' => 42.75], now()->subDays(10));
        $this->scoreSnapshot($challenge, $aminaEntry, 88.10, 2, ['public_votes' => 45.95, 'jury' => 42.15], now()->subDays(10));
        $this->ballot($challenge, $users->get('fan'), $kwameEntry, now()->subDays(14));
        $this->ballot($challenge, $users->get('fans'), $aminaEntry, now()->subDays(14));

        DB::table('challenge_entry_advancements')->updateOrInsert(
            ['challenge_id' => $challenge->id, 'challenge_entry_id' => $kwameEntry->id, 'to_stage_id' => $juryStage->id],
            [
                'from_stage_id' => $publicStage->id,
                'status' => 'advanced',
                'reason' => 'Top two entries advanced from community voting.',
                'decided_by_user_id' => $users->get('admin')->id,
                'decided_at' => now()->subDays(12),
                'created_at' => now()->subDays(12),
                'updated_at' => now()->subDays(12),
            ],
        );
        $flag = ChallengeIntegrityFlag::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'challenge_entry_id' => $kwameEntry->id, 'type' => 'view_velocity_review'],
            [
                'user_id' => $host->id,
                'severity' => 'low',
                'status' => 'dismissed',
                'evidence' => ['peak_views_per_minute' => 24, 'threshold' => 100, 'seeded' => true],
                'resolved_by_user_id' => $users->get('admin')->id,
                'resolution' => 'Organic traffic from the featured community post.',
                'resolved_at' => now()->subDays(10),
            ],
        );
        ChallengeSelectionDecision::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'challenge_entry_id' => $kwameEntry->id, 'decision' => 'selected_winner'],
            [
                'decided_by_user_id' => $users->get('admin')->id,
                'rank' => 1,
                'reason' => 'Highest finalized score after integrity review.',
                'decided_at' => now()->subDays(9),
            ],
        );
        $winner = ChallengeWinner::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'rank' => 1],
            [
                'challenge_entry_id' => $kwameEntry->id,
                'status' => 'confirmed',
                'final_score' => 92.75,
                'confirmed_at' => now()->subDays(9),
                'metadata' => ['seeded' => true, 'integrity_flag_id' => $flag->id],
            ],
        );
        $allocation = ChallengeRewardAllocation::query()->updateOrCreate(
            ['winner_id' => $winner->id, 'prize_id' => $prize->id],
            [
                'challenge_id' => $challenge->id,
                'recipient_user_id' => $host->id,
                'status' => 'completed',
                'currency' => 'USD',
                'amount' => 120,
                'metadata' => ['seeded' => true],
                'allocated_at' => now()->subDays(9),
                'processed_at' => now()->subDays(8),
            ],
        );
        $walletTransaction = WalletTransaction::query()->where('reference', '11000000-0000-4000-8000-000000000002')->firstOrFail();
        ChallengeRewardTransaction::query()->updateOrCreate(
            ['idempotency_key' => "challenge-reward:{$allocation->id}"],
            [
                'allocation_id' => $allocation->id,
                'status' => 'completed',
                'provider' => 'kulsah_wallet',
                'provider_reference' => $walletTransaction->reference,
                'wallet_transaction_id' => $walletTransaction->id,
                'attempts' => 1,
                'failure_reason' => null,
                'metadata' => ['seeded' => true],
                'processed_at' => now()->subDays(8),
            ],
        );
        CreatorBattleSettlement::query()->updateOrCreate(
            ['challenge_id' => $challenge->id],
            [
                'challenge_winner_id' => $winner->id,
                'challenge_entry_id' => $kwameEntry->id,
                'recipient_user_id' => $host->id,
                'status' => 'completed',
                'vote_count' => 420,
                'vote_coin_amount' => 4200,
                'conversion_rate' => 0.028571,
                'usd_amount' => 120,
                'idempotency_key' => "creator-battle-settlement:{$challenge->id}",
                'wallet_transaction_id' => $walletTransaction->id,
                'attempts' => 1,
                'failure_reason' => null,
                'metadata' => ['seeded' => true, 'runner_up_entry_id' => $aminaEntry->id],
                'processed_at' => now()->subDays(8),
            ],
        );
        $this->audit($challenge, $users->get('admin'), 'challenge_finalized', ['status' => 'integrity_review'], ['status' => 'completed', 'winner_id' => $winner->id], now()->subDays(9));
    }

    private function seedScheduledChallenge($users, $videos): void
    {
        $host = $users->get('amina.designs');
        $challenge = $this->challenge('weekend-home-refresh', [
            'created_by_user_id' => $host->id,
            'host_type' => 'creator',
            'host_user_id' => $host->id,
            'title' => 'Weekend Home Refresh',
            'description' => 'Share one affordable change that makes a room more useful, calm, or joyful.',
            'instructions' => 'Show the before, the change, and the result in 45 seconds or less.',
            'hashtag' => '#WeekendRefresh',
            'visibility' => 'public',
            'mode' => 'open',
            'status' => 'scheduled',
            'judging_strategy' => 'points',
            'winner_selection_method' => 'manual',
            'registration_starts_at' => now()->addDays(3),
            'registration_ends_at' => now()->addDays(12),
            'submission_starts_at' => now()->addDays(4),
            'submission_ends_at' => now()->addDays(14),
            'voting_starts_at' => now()->addDays(14),
            'voting_ends_at' => now()->addDays(17),
            'results_publish_at' => now()->addDays(18),
            'show_leaderboard' => true,
            'leaderboard_mode' => 'live',
            'max_participants' => 100,
            'max_entries_per_creator' => 1,
            'official_sound_id' => $videos->get('bathroom-design')->id,
            'moderation_status' => 'approved',
            'rules_version' => 1,
            'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false],
            'integrity_configuration' => ['review_before_finalization' => true],
            'metadata' => ['seeded' => true, 'category' => 'design', 'is_new' => true, 'reward' => 'Creator feature'],
            'published_at' => now(),
        ]);
        $this->collaborator($challenge, $host, 'owner', $host);
        $this->prize($challenge, null, 1, 1, 'feature', 'Kulsah Design Feature', null, null);
        $this->rule($challenge, 'entry', 'duration_seconds', '<=', [45]);
        $this->media($challenge, $videos->get('bathroom-design'), 'challenge_video', 0);
        $this->component($challenge, 'public_votes', 10000, null, 1);
        $this->audit($challenge, $host, 'challenge_scheduled', ['status' => 'approved'], ['status' => 'scheduled']);
    }

    private function challenge(string $slug, array $attributes): Challenge
    {
        return $this->upsert(Challenge::class, ['slug' => $slug], $attributes);
    }

    private function collaborator(Challenge $challenge, User $user, string $role, User $inviter): ChallengeCollaborator
    {
        return ChallengeCollaborator::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'user_id' => $user->id],
            ['role' => $role, 'invited_by_user_id' => $inviter->id, 'status' => 'accepted', 'accepted_at' => now()->subDays(3)],
        );
    }

    private function sponsor(Challenge $challenge, string $name, string $type, string $website): ChallengeSponsor
    {
        return ChallengeSponsor::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'sponsor_name' => $name],
            ['sponsor_type' => $type, 'website_url' => $website, 'status' => 'approved', 'display_order' => 1, 'metadata' => ['seeded' => true]],
        );
    }

    private function rewardPool(Challenge $challenge, ?ChallengeSponsor $sponsor, string $currency, float $committed, float $funded, float $reserved, float $distributed, string $status): ChallengeRewardPool
    {
        return ChallengeRewardPool::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'currency' => $currency],
            ['sponsor_id' => $sponsor?->id, 'funding_source_type' => 'sponsor', 'funding_source_id' => $sponsor?->id, 'committed_amount' => $committed, 'funded_amount' => $funded, 'reserved_amount' => $reserved, 'distributed_amount' => $distributed, 'status' => $status, 'funded_at' => now()->subDays(5)],
        );
    }

    private function prize(Challenge $challenge, ?ChallengeRewardPool $pool, int $rankFrom, int $rankTo, string $type, string $title, ?string $currency, ?float $amount, ?int $quantity = 1): ChallengePrize
    {
        return ChallengePrize::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'title' => $title],
            ['rank_from' => $rankFrom, 'rank_to' => $rankTo, 'reward_type' => $type, 'description' => "{$title} reward", 'currency' => $currency, 'amount' => $amount, 'quantity' => $quantity, 'reward_pool_id' => $pool?->id, 'metadata' => ['seeded' => true]],
        );
    }

    private function rule(Challenge $challenge, string $scope, string $type, string $operator, array $value): ChallengeRule
    {
        return ChallengeRule::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'scope' => $scope, 'rule_type' => $type, 'rules_version' => 1],
            ['operator' => $operator, 'value' => $value, 'is_required' => true],
        );
    }

    private function media(Challenge $challenge, Video $video, string $role, int $sortOrder): ChallengeMedia
    {
        return ChallengeMedia::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'video_id' => $video->id, 'role' => $role],
            ['sort_order' => $sortOrder, 'metadata' => ['seeded' => true, 'cover_url' => $video->poster_url]],
        );
    }

    private function invite(Challenge $challenge, User $user, User $inviter, string $status, $acceptedAt = null): ChallengeInvite
    {
        return ChallengeInvite::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'invited_user_id' => $user->id],
            ['invited_by_user_id' => $inviter->id, 'status' => $status, 'token' => "seed-invite-{$challenge->id}-{$user->id}", 'expires_at' => now()->addMonth(), 'accepted_at' => $acceptedAt ?? now()->subDays(3)],
        );
    }

    private function entry(Challenge $challenge, User $creator, Video $video, int $number, float $score, int $rank, $submittedAt = null): ChallengeEntry
    {
        return $this->upsert(ChallengeEntry::class, ['challenge_id' => $challenge->id, 'video_id' => $video->id], [
            'creator_id' => $creator->id,
            'submission_number' => $number,
            'caption' => $video->caption,
            'status' => 'approved',
            'moderation_status' => 'approved',
            'eligibility_status' => 'eligible',
            'submitted_at' => $submittedAt ?? now()->subDays(2),
            'approved_at' => $submittedAt ?? now()->subDays(2),
            'current_score' => $score,
            'current_rank' => $rank,
        ]);
    }

    private function eligibility(ChallengeEntry $entry, array $evaluation, $evaluatedAt = null): ChallengeEntryEligibilitySnapshot
    {
        return ChallengeEntryEligibilitySnapshot::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'rules_version' => 1],
            ['eligible' => true, 'evaluation' => ['passed' => $evaluation, 'failures' => []], 'evaluated_at' => $evaluatedAt ?? now()->subDays(2)],
        );
    }

    private function stage(Challenge $challenge, int $sequence, string $name, string $type, $startsAt, $endsAt): ChallengeJudgingStage
    {
        return ChallengeJudgingStage::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'sequence' => $sequence],
            ['name' => $name, 'stage_type' => $type, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'configuration' => ['seeded' => true]],
        );
    }

    private function component(Challenge $challenge, string $type, int $weight, ?string $normalization, ?float $pointValue = null): ChallengeScoringComponent
    {
        return ChallengeScoringComponent::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'type' => $type, 'rules_version' => 1],
            ['weight_bps' => $weight, 'point_value' => $pointValue, 'normalization_method' => $normalization, 'enabled' => true, 'configuration' => ['seeded' => true]],
        );
    }

    private function juryMember(Challenge $challenge, User $user, string $role, $acceptedAt = null): ChallengeJuryMember
    {
        return ChallengeJuryMember::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'user_id' => $user->id],
            ['role' => $role, 'weight_bps' => 10000, 'status' => 'accepted', 'invited_at' => now()->subDays(4), 'accepted_at' => $acceptedAt ?? now()->subDays(3)],
        );
    }

    private function criterion(Challenge $challenge, string $name, string $description, int $weight, int $sortOrder): ChallengeJuryCriterion
    {
        return ChallengeJuryCriterion::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'name' => $name],
            ['description' => $description, 'min_score' => 0, 'max_score' => 100, 'weight_bps' => $weight, 'sort_order' => $sortOrder],
        );
    }

    private function juryScore(Challenge $challenge, ChallengeEntry $entry, ChallengeJuryMember $member, ChallengeJuryCriterion $criterion, float $score, string $comment, $submittedAt = null): ChallengeJuryScore
    {
        return ChallengeJuryScore::query()->updateOrCreate(
            ['jury_member_id' => $member->id, 'challenge_entry_id' => $entry->id, 'criterion_id' => $criterion->id],
            ['challenge_id' => $challenge->id, 'score' => $score, 'comment' => $comment, 'submitted_at' => $submittedAt ?? now()->subHour()],
        );
    }

    private function ballot(Challenge $challenge, User $voter, ChallengeEntry $entry, $submittedAt = null): ChallengeBallot
    {
        $ballot = ChallengeBallot::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'voter_id' => $voter->id],
            ['status' => 'submitted', 'submitted_at' => $submittedAt ?? now()->subHours(2)],
        );
        ChallengeBallotChoice::query()->updateOrCreate(
            ['ballot_id' => $ballot->id, 'challenge_entry_id' => $entry->id],
            ['rank' => null, 'points' => 1],
        );

        return $ballot;
    }

    private function entryScore(Challenge $challenge, ChallengeEntry $entry, ChallengeScoringComponent $component, float $raw, float $normalized, float $weighted, $calculatedAt = null): ChallengeEntryScore
    {
        return ChallengeEntryScore::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'scoring_component_id' => $component->id],
            ['challenge_id' => $challenge->id, 'raw_value' => $raw, 'normalized_value' => $normalized, 'weighted_value' => $weighted, 'calculated_at' => $calculatedAt ?? now()->subHour(), 'metadata' => ['seeded' => true]],
        );
    }

    private function scoreSnapshot(Challenge $challenge, ChallengeEntry $entry, float $score, int $rank, array $components, $capturedAt = null): ChallengeScoreSnapshot
    {
        return ChallengeScoreSnapshot::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'reason' => 'seeded_demo'],
            ['challenge_id' => $challenge->id, 'final_score' => $score, 'rank' => $rank, 'components' => $components, 'captured_at' => $capturedAt ?? now()->subHour()],
        );
    }

    private function audit(Challenge $challenge, User $actor, string $action, array $before, array $after, $createdAt = null): ChallengeAuditLog
    {
        return ChallengeAuditLog::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => $action],
            ['subject_type' => Challenge::class, 'subject_id' => $challenge->id, 'before' => $before, 'after' => $after, 'metadata' => ['seeded' => true], 'ip_address' => '127.0.0.1', 'user_agent' => 'Kulsah Database Seeder', 'created_at' => $createdAt ?? now()],
        );
    }

    /**
     * Force-fills lifecycle-managed fields while keeping each demo record idempotent.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    private function upsert(string $modelClass, array $identity, array $attributes): Model
    {
        /** @var TModel $model */
        $model = $modelClass::query()->firstOrNew($identity);
        $model->forceFill($identity + $attributes)->save();

        return $model;
    }
}
