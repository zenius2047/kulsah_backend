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
use App\Models\ChallengeInvite;
use App\Models\ChallengeJudgingStage;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use App\Models\ChallengeRule;
use App\Models\ChallengeScoreSnapshot;
use App\Models\ChallengeScoringComponent;
use App\Models\ChallengeSelectionDecision;
use App\Models\ChallengeWinner;
use App\Models\CreatorBattleSettlement;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChallengeVolumeSeeder extends Seeder
{
    public const STANDARD_CHALLENGE_COUNT = 200;

    public const CREATOR_BATTLE_COUNT = 200;

    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'admin', 'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $creators = collect([
                $users->get('creator'), $users->get('zuri.moves'), $users->get('tunde.creates'),
                $users->get('naledi.fit'), $users->get('kwame.frames'), $users->get('amina.designs'),
            ]);
            $baseVideos = Video::query()
                ->with('user')
                ->whereNull('duet_source_video_id')
                ->where('status', 'ready')
                ->where('visibility', 'public')
                ->where('source_key', 'like', 'demo/%')
                ->orderBy('id')
                ->get()
                ->values();
            $duetsByCreator = Video::query()
                ->whereNotNull('duet_source_video_id')
                ->where('status', 'ready')
                ->where('source_key', 'like', 'demo/duets/%')
                ->orderBy('id')
                ->get()
                ->groupBy('user_id');

            if ($baseVideos->isEmpty() || $duetsByCreator->count() !== $creators->count()) {
                throw new \RuntimeException('ChallengeVolumeSeeder requires the base feed and volume duet videos.');
            }

            $this->seedStandardChallenges($users, $creators, $baseVideos);
            $this->seedCreatorBattles($users, $creators, $duetsByCreator);
        });
    }

    private function seedStandardChallenges($users, $creators, $videos): void
    {
        $themes = ['Dance', 'Comedy', 'Fitness', 'Travel', 'Design', 'Storytelling', 'Food', 'Fashion'];
        $prompts = [
            'Show a transformation in under 30 seconds.',
            'Turn an everyday moment into a memorable story.',
            'Teach the community one practical creative skill.',
            'Respond to the weekly sound with your own style.',
            'Celebrate something distinctive about your city.',
        ];

        for ($number = 1; $number <= self::STANDARD_CHALLENGE_COUNT; $number++) {
            $host = $creators->get(($number - 1) % $creators->count());
            $video = $videos->get(($number - 1) % $videos->count());
            $entryCreator = $video->user;
            $theme = $themes[($number - 1) % count($themes)];
            $status = match ($number % 4) {
                0 => 'scheduled',
                1, 2 => 'active',
                default => 'submissions_closed',
            };
            $scheduled = $status === 'scheduled';
            $submissionsClosed = $status === 'submissions_closed';
            $submissionStartsAt = $scheduled ? now()->addDays(2) : now()->subDays($submissionsClosed ? 12 : 4);
            $submissionEndsAt = $scheduled ? now()->addDays(12) : ($submissionsClosed ? now()->subDays(2) : now()->addDays(8));
            $votingStartsAt = $scheduled ? now()->addDays(12) : ($submissionsClosed ? now()->subDay() : now()->subDay());
            $votingEndsAt = $scheduled ? now()->addDays(16) : now()->addDays($submissionsClosed ? 5 : 10);
            $slug = sprintf('demo-open-challenge-%03d', $number);
            $challenge = $this->upsert(Challenge::class, ['slug' => $slug], [
                'created_by_user_id' => $host->id,
                'host_type' => 'creator',
                'host_user_id' => $host->id,
                'host_organization_id' => null,
                'title' => sprintf('%s Community Challenge %03d', $theme, $number),
                'description' => $prompts[($number - 1) % count($prompts)],
                'instructions' => 'Post one original vertical video, use the challenge hashtag, and keep the entry community-safe.',
                'category_id' => null,
                'hashtag' => sprintf('#Kulsah%s%03d', $theme, $number),
                'visibility' => 'public',
                'mode' => 'open',
                'status' => $status,
                'judging_strategy' => 'points',
                'winner_selection_method' => 'automatic_score',
                'registration_starts_at' => $submissionStartsAt->copy()->subDays(2),
                'registration_ends_at' => $submissionEndsAt,
                'submission_starts_at' => $submissionStartsAt,
                'submission_ends_at' => $submissionEndsAt,
                'voting_starts_at' => $votingStartsAt,
                'voting_ends_at' => $votingEndsAt,
                'judging_starts_at' => $votingEndsAt->copy(),
                'judging_ends_at' => $votingEndsAt->copy()->addDays(2),
                'results_publish_at' => $votingEndsAt->copy()->addDays(3),
                'show_leaderboard' => true,
                'leaderboard_mode' => 'live',
                'max_participants' => 100 + ($number % 400),
                'max_entries_per_creator' => 1,
                'official_sound_id' => $video->id,
                'moderation_status' => 'approved',
                'rules_version' => 1,
                'voting_configuration' => ['mode' => 'single_choice', 'allow_self_voting' => false],
                'integrity_configuration' => ['review_before_finalization' => true],
                'metadata' => [
                    'seeded' => true,
                    'seed_group' => 'volume_standard_challenges',
                    'category' => strtolower($theme),
                    'reward' => 'Community feature',
                ],
                'published_at' => now()->subDays(($number % 30) + 1),
                'finalized_at' => null,
                'cancelled_at' => null,
            ]);

            $this->collaborator($challenge, $host, 'owner', $host);
            $this->rule($challenge, 'entry', 'duration_seconds', '<=', [45]);
            $this->prize($challenge, 'Kulsah Community Feature', 'feature', null, null);
            $this->media($challenge, $video, 'challenge_video');
            $component = $this->component($challenge, 'public_votes', 10000, 1);
            $this->stage($challenge, 'Community Voting', 'public_voting', $votingStartsAt, $votingEndsAt);

            if (! $scheduled) {
                $score = 40 + fmod($number * 1.37, 58);
                $entry = $this->entry($challenge, $entryCreator, $video, $score, 1);
                $this->eligibility($entry);
                $this->entryScore($challenge, $entry, $component, 20 + ($number % 300), $score, $score);
                $this->scoreSnapshot($challenge, $entry, $score, 1);
                $this->ballot($challenge, $users->get($number % 2 === 0 ? 'fan' : 'fans'), $entry);
            }

            $this->audit($challenge, $host, 'volume_challenge_seeded');
        }
    }

    private function seedCreatorBattles($users, $creators, $duetsByCreator): void
    {
        $prompts = [
            'One sound, two movement styles.',
            'Tell the same story from opposite perspectives.',
            'Turn one object into two creative concepts.',
            'Create the strongest thirty-second transformation.',
            'Answer the weekly prompt with your signature style.',
        ];

        for ($number = 1; $number <= self::CREATOR_BATTLE_COUNT; $number++) {
            $host = $creators->get(($number - 1) % $creators->count());
            $opponent = $creators->get($number % $creators->count());
            $hostVideos = $duetsByCreator->get($host->id)->values();
            $opponentVideos = $duetsByCreator->get($opponent->id)->values();
            $hostVideo = $hostVideos->get(($number - 1) % $hostVideos->count());
            $opponentVideo = $opponentVideos->get(($number - 1) % $opponentVideos->count());
            $completed = $number % 4 === 0;
            $submissionStartsAt = $completed ? now()->subDays(20) : now()->subDays(4);
            $submissionEndsAt = $completed ? now()->subDays(16) : now()->addDays(3);
            $votingStartsAt = $completed ? now()->subDays(15) : now()->subDay();
            $votingEndsAt = $completed ? now()->subDays(10) : now()->addDays(5);
            $slug = sprintf('demo-creator-battle-%03d', $number);
            $challenge = $this->upsert(Challenge::class, ['slug' => $slug], [
                'created_by_user_id' => $host->id,
                'host_type' => 'creator',
                'host_user_id' => $host->id,
                'host_organization_id' => null,
                'title' => sprintf('%s vs %s Battle %03d', $host->name, $opponent->name, $number),
                'description' => $prompts[($number - 1) % count($prompts)],
                'instructions' => 'Watch both creator entries before casting one KulCoin community vote.',
                'category_id' => null,
                'hashtag' => sprintf('#KulsahBattle%03d', $number),
                'visibility' => 'public',
                'mode' => 'creator_battle',
                'status' => $completed ? 'completed' : 'active',
                'judging_strategy' => 'weighted_normalized',
                'winner_selection_method' => 'automatic_score',
                'registration_starts_at' => $submissionStartsAt->copy()->subDays(2),
                'registration_ends_at' => $submissionEndsAt,
                'submission_starts_at' => $submissionStartsAt,
                'submission_ends_at' => $submissionEndsAt,
                'voting_starts_at' => $votingStartsAt,
                'voting_ends_at' => $votingEndsAt,
                'judging_starts_at' => $votingEndsAt->copy(),
                'judging_ends_at' => $votingEndsAt->copy()->addDay(),
                'results_publish_at' => $votingEndsAt->copy()->addDays(2),
                'show_leaderboard' => true,
                'leaderboard_mode' => $completed ? 'final' : 'live',
                'max_participants' => 2,
                'max_entries_per_creator' => 1,
                'official_sound_id' => $hostVideo->duet_source_video_id,
                'moderation_status' => 'approved',
                'rules_version' => 1,
                'voting_configuration' => [
                    'mode' => 'single_choice',
                    'allow_self_voting' => false,
                    'vote_coin_price' => 10,
                ],
                'integrity_configuration' => ['review_before_finalization' => true],
                'metadata' => [
                    'seeded' => true,
                    'seed_group' => 'volume_creator_battles',
                    'host_username' => $host->username,
                    'opponent_username' => $opponent->username,
                    'reward' => 'Community vote pool',
                ],
                'published_at' => $submissionStartsAt->copy()->subDay(),
                'finalized_at' => $completed ? now()->subDays(8) : null,
                'cancelled_at' => null,
            ]);

            $this->collaborator($challenge, $host, 'owner', $host);
            $this->collaborator($challenge, $opponent, 'participant', $host);
            ChallengeInvite::query()->updateOrCreate(
                ['challenge_id' => $challenge->id, 'invited_user_id' => $opponent->id],
                [
                    'invited_by_user_id' => $host->id,
                    'status' => 'accepted',
                    'token' => sprintf('volume-battle-invite-%03d', $number),
                    'expires_at' => $submissionEndsAt,
                    'accepted_at' => $submissionStartsAt,
                    'declined_at' => null,
                ],
            );
            $this->rule($challenge, 'entry', 'invited_creator_only', '=', [true]);
            $this->prize($challenge, 'Creator Battle Vote Pool', 'cash', 'USD', 25 + ($number % 100));
            $this->media($challenge, $hostVideo, 'host_entry_preview');
            $this->media($challenge, $opponentVideo, 'opponent_entry_preview');
            $component = $this->component($challenge, 'public_votes', 10000, 1);
            $this->stage($challenge, 'Creator Battle Voting', 'public_voting', $votingStartsAt, $votingEndsAt);

            $hostScore = 55 + fmod($number * 1.17, 42);
            $opponentScore = 54 + fmod($number * 1.11, 41);
            $hostIsWinner = $hostScore >= $opponentScore;
            $hostEntry = $this->entry($challenge, $host, $hostVideo, $hostScore, $hostIsWinner ? 1 : 2);
            $opponentEntry = $this->entry($challenge, $opponent, $opponentVideo, $opponentScore, $hostIsWinner ? 2 : 1);
            $this->eligibility($hostEntry);
            $this->eligibility($opponentEntry);
            $this->entryScore($challenge, $hostEntry, $component, 100 + $number, $hostScore, $hostScore);
            $this->entryScore($challenge, $opponentEntry, $component, 95 + $number, $opponentScore, $opponentScore);
            $this->scoreSnapshot($challenge, $hostEntry, $hostScore, $hostIsWinner ? 1 : 2);
            $this->scoreSnapshot($challenge, $opponentEntry, $opponentScore, $hostIsWinner ? 2 : 1);
            $this->ballot($challenge, $users->get('fan'), $hostEntry);
            $this->ballot($challenge, $users->get('fans'), $opponentEntry);

            if ($completed) {
                $winningEntry = $hostIsWinner ? $hostEntry : $opponentEntry;
                $winnerUser = $hostIsWinner ? $host : $opponent;
                $winningScore = $hostIsWinner ? $hostScore : $opponentScore;
                ChallengeSelectionDecision::query()->updateOrCreate(
                    [
                        'challenge_id' => $challenge->id,
                        'challenge_entry_id' => $winningEntry->id,
                        'decision' => 'selected_winner',
                    ],
                    [
                        'decided_by_user_id' => $users->get('admin')->id,
                        'rank' => 1,
                        'reason' => 'Highest seeded community score after voting closed.',
                        'decided_at' => now()->subDays(8),
                    ],
                );
                $winner = ChallengeWinner::query()->updateOrCreate(
                    ['challenge_id' => $challenge->id, 'rank' => 1],
                    [
                        'challenge_entry_id' => $winningEntry->id,
                        'status' => 'confirmed',
                        'final_score' => $winningScore,
                        'confirmed_at' => now()->subDays(8),
                        'replaced_at' => null,
                        'replaced_by_winner_id' => null,
                        'metadata' => ['seeded' => true, 'seed_group' => 'volume_creator_battles'],
                    ],
                );
                CreatorBattleSettlement::query()->updateOrCreate(
                    ['challenge_id' => $challenge->id],
                    [
                        'challenge_winner_id' => $winner->id,
                        'challenge_entry_id' => $winningEntry->id,
                        'recipient_user_id' => $winnerUser->id,
                        'status' => 'pending',
                        'vote_count' => 200 + $number,
                        'vote_coin_amount' => (200 + $number) * 10,
                        'conversion_rate' => 0.01,
                        'usd_amount' => 20 + ($number / 10),
                        'idempotency_key' => sprintf('volume-creator-battle-settlement-%03d', $number),
                        'wallet_transaction_id' => null,
                        'attempts' => 0,
                        'failure_reason' => null,
                        'metadata' => ['seeded' => true, 'seed_group' => 'volume_creator_battles'],
                        'processed_at' => null,
                    ],
                );
            }

            $this->audit($challenge, $host, $completed ? 'volume_battle_completed' : 'volume_battle_started');
        }
    }

    private function collaborator(Challenge $challenge, User $user, string $role, User $inviter): void
    {
        ChallengeCollaborator::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'user_id' => $user->id],
            [
                'role' => $role,
                'invited_by_user_id' => $inviter->id,
                'status' => 'accepted',
                'accepted_at' => now()->subDays(3),
            ],
        );
    }

    private function rule(Challenge $challenge, string $scope, string $type, string $operator, array $value): void
    {
        ChallengeRule::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'scope' => $scope, 'rule_type' => $type, 'rules_version' => 1],
            ['operator' => $operator, 'value' => $value, 'is_required' => true],
        );
    }

    private function prize(Challenge $challenge, string $title, string $type, ?string $currency, ?float $amount): void
    {
        ChallengePrize::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'title' => $title],
            [
                'rank_from' => 1,
                'rank_to' => 1,
                'reward_type' => $type,
                'description' => $title.' reward',
                'currency' => $currency,
                'amount' => $amount,
                'quantity' => 1,
                'reward_pool_id' => null,
                'metadata' => ['seeded' => true],
            ],
        );
    }

    private function media(Challenge $challenge, Video $video, string $role): void
    {
        ChallengeMedia::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'video_id' => $video->id, 'role' => $role],
            ['sort_order' => 0, 'metadata' => ['seeded' => true, 'cover_url' => $video->poster_url]],
        );
    }

    private function component(Challenge $challenge, string $type, int $weight, float $pointValue): ChallengeScoringComponent
    {
        return ChallengeScoringComponent::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'type' => $type, 'rules_version' => 1],
            [
                'weight_bps' => $weight,
                'point_value' => $pointValue,
                'normalization_method' => 'max_ratio',
                'enabled' => true,
                'configuration' => ['seeded' => true],
            ],
        );
    }

    private function stage(Challenge $challenge, string $name, string $type, $startsAt, $endsAt): void
    {
        ChallengeJudgingStage::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'sequence' => 1],
            [
                'name' => $name,
                'stage_type' => $type,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'configuration' => ['seeded' => true],
            ],
        );
    }

    private function entry(Challenge $challenge, User $creator, Video $video, float $score, int $rank): ChallengeEntry
    {
        return $this->upsert(ChallengeEntry::class, [
            'challenge_id' => $challenge->id,
            'video_id' => $video->id,
        ], [
            'creator_id' => $creator->id,
            'submission_number' => 1,
            'caption' => $video->caption,
            'status' => 'active',
            'moderation_status' => 'approved',
            'eligibility_status' => 'eligible',
            'submitted_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2),
            'current_score' => $score,
            'current_rank' => $rank,
        ]);
    }

    private function eligibility(ChallengeEntry $entry): void
    {
        ChallengeEntryEligibilitySnapshot::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'rules_version' => 1],
            [
                'eligible' => true,
                'evaluation' => ['passed' => ['ownership' => true, 'duration' => true], 'failures' => []],
                'evaluated_at' => now()->subDays(2),
            ],
        );
    }

    private function entryScore(Challenge $challenge, ChallengeEntry $entry, ChallengeScoringComponent $component, float $raw, float $normalized, float $weighted): void
    {
        ChallengeEntryScore::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'scoring_component_id' => $component->id],
            [
                'challenge_id' => $challenge->id,
                'raw_value' => $raw,
                'normalized_value' => $normalized,
                'weighted_value' => $weighted,
                'calculated_at' => now()->subHour(),
                'metadata' => ['seeded' => true],
            ],
        );
    }

    private function scoreSnapshot(Challenge $challenge, ChallengeEntry $entry, float $score, int $rank): void
    {
        ChallengeScoreSnapshot::query()->updateOrCreate(
            ['challenge_entry_id' => $entry->id, 'reason' => 'volume_seed'],
            [
                'challenge_id' => $challenge->id,
                'final_score' => $score,
                'rank' => $rank,
                'components' => ['public_votes' => $score],
                'captured_at' => now()->subHour(),
            ],
        );
    }

    private function ballot(Challenge $challenge, User $voter, ChallengeEntry $entry): void
    {
        $ballot = ChallengeBallot::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'voter_id' => $voter->id],
            ['status' => 'submitted', 'submitted_at' => now()->subHour()],
        );
        ChallengeBallotChoice::query()->updateOrCreate(
            ['ballot_id' => $ballot->id, 'challenge_entry_id' => $entry->id],
            ['rank' => null, 'points' => 1],
        );
    }

    private function audit(Challenge $challenge, User $actor, string $action): void
    {
        ChallengeAuditLog::query()->updateOrCreate(
            ['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => $action],
            [
                'subject_type' => Challenge::class,
                'subject_id' => $challenge->id,
                'before' => null,
                'after' => ['status' => $challenge->status->value],
                'metadata' => ['seeded' => true],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Kulsah Challenge Volume Seeder',
                'created_at' => now(),
            ],
        );
    }

    /**
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
