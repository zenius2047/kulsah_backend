<?php

namespace App\Services;

use App\Enums\LiveBattleStatus;
use App\Events\LiveDirectoryUpdated;
use App\Events\LiveUpdated;
use App\Models\LiveBattle;
use App\Models\KulCoinTransaction;
use App\Notifications\LiveBattleInvitationNotification;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveBattleService
{
    public function __construct(
        private readonly LiveAuthorizationService $authorization,
        private readonly KulCoinService $kulCoins,
    )
    {
    }

    public function invite(LiveSession $creatorLive, LiveSession|User $opponent, User $actor): LiveBattle
    {
        if ((int) $creatorLive->creator_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['battle' => 'Only the creator can invite another Live to a battle.']);
        }

        $opponentUser = $opponent instanceof User ? $opponent : $opponent->creator;
        abort_if((int) $creatorLive->creator_id === (int) $opponentUser->id, 422, 'Choose another creator.');
        abort_unless($creatorLive->status?->value === 'live', 422, 'Your stream must be live to invite a creator.');
        abort_unless($opponentUser->roles()->where('name', 'creator')->exists(), 422, 'Choose a creator.');
        abort_unless(app(RealtimePresenceService::class)->isOnline($opponentUser), 422, 'The invited creator must be online.');
        $opponentLive = LiveSession::query()->where('creator_id', $opponentUser->id)->where('status', 'live')->latest()->first();
        $sharedStage = $opponentLive === null;
        if ($sharedStage) $this->authorization->assertViewerCanJoin($opponentUser, $creatorLive);
        // Online creators without their own broadcast join the host's stage on acceptance.
        $opponentLive ??= $creatorLive;
        $battleLives = LiveSession::query()->whereIn('id', [$creatorLive->id, $opponentLive->id])->get();
        foreach ($battleLives as $battleLive) {
            if ($battleLive->live_type !== 'battle') {
                $battleLive->forceFill(['live_type' => 'battle'])->save();
                LiveDirectoryUpdated::dispatch($battleLive->fresh('creator'), 'battle');
            }
        }
        $existing = LiveBattle::query()->where('creator_live_session_id', $creatorLive->id)
            ->where('opponent_id', $opponentUser->id)->whereIn('status', ['pending', 'accepted', 'active'])->first();
        if ($existing) return $existing;

        $battle = LiveBattle::query()->create([
            'public_id' => (string) Str::uuid(),
            'creator_live_session_id' => $creatorLive->id,
            'opponent_live_session_id' => $opponentLive->id,
            'creator_id' => $creatorLive->creator_id,
            'opponent_id' => $opponentUser->id,
            'status' => LiveBattleStatus::PENDING,
            'creator_score' => 0,
            'opponent_score' => 0,
            'invited_by_id' => $actor->id,
            'metadata' => ['shared_stage' => $sharedStage],
        ]);
        $opponentUser->notify(new LiveBattleInvitationNotification($creatorLive, $battle));
        return $battle;
    }

    public function accept(LiveBattle $battle, User $actor): LiveBattle
    {
        if ((int) $battle->opponent_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['battle' => 'Only the invited creator can accept this battle.']);
        }

        abort_unless($battle->creatorLive->status?->value === 'live', 422, 'The host stream has ended.');
        abort_unless(app(RealtimePresenceService::class)->isOnline($actor), 422, 'The invited creator must be online.');

        if (! $battle->status->canTransitionTo(LiveBattleStatus::ACCEPTED)) {
            throw ValidationException::withMessages(['battle' => 'This battle cannot be accepted.']);
        }

        if ($battle->opponentLive->status?->value !== 'live') {
            $battle->fill([
                'opponent_live_session_id' => $battle->creator_live_session_id,
                'metadata' => array_merge($battle->metadata ?? [], ['shared_stage' => true]),
            ]);
        }

        if ($battle->metadata['shared_stage'] ?? false) {
            $cohosts = app(LiveCohostService::class);
            if (! $this->authorization->canPublish($actor, $battle->creatorLive)) {
                $invitation = $cohosts->invite($battle->creatorLive, $battle->creator, $actor);
                // An existing viewer request needs host approval before the recipient joins.
                if ((int) $invitation->invitee_id === (int) $battle->creator_id && $invitation->status->value === 'pending') {
                    $cohosts->accept($invitation, $battle->creator);
                    $invitation->refresh();
                }
                $cohosts->accept($invitation, $actor);
            }
        }

        $battle->update([
            'status' => LiveBattleStatus::ACTIVE,
            'accepted_at' => now(),
            'started_at' => now(),
        ]);

        $battle->participants()->firstOrCreate(
            ['user_id' => $battle->creator_id],
            ['live_session_id' => $battle->creator_live_session_id, 'side' => 'creator', 'score' => 0]
        );
        $battle->participants()->firstOrCreate(
            ['user_id' => $battle->opponent_id],
            ['live_session_id' => $battle->opponent_live_session_id, 'side' => 'opponent', 'score' => 0]
        );

        LiveUpdated::dispatch($battle->creatorLive->fresh('creator'), 'battle_start');

        return $battle->fresh(['participants', 'creatorLive', 'opponentLive']);
    }

    public function score(LiveBattle $battle): LiveBattle
    {
        // Gifts currently target a stream owner, so a shared stream has no separate side totals.
        abort_if($battle->metadata['shared_stage'] ?? false, 422, 'Separate battle scores are not available on a shared stream.');
        $battle->loadMissing(['creatorLive', 'opponentLive']);

        // Combine verified stream gifts with persisted viewer votes; neither total comes from client-supplied scores.
        $creatorVotes = (int) $battle->participants()->where('user_id', $battle->creator_id)->value('score');
        $opponentVotes = (int) $battle->participants()->where('user_id', $battle->opponent_id)->value('score');
        $creatorScore = (int) $battle->creatorLive->gift_value_kc + $creatorVotes;
        $opponentScore = (int) $battle->opponentLive->gift_value_kc + $opponentVotes;

        $battle->update([
            'creator_score' => max(0, $creatorScore),
            'opponent_score' => max(0, $opponentScore),
        ]);

        LiveUpdated::dispatch($battle->creatorLive->fresh('creator'), 'battle_score');

        return $battle->fresh();
    }

    public function vote(LiveBattle $battle, User $voter, array $data): array
    {
        $this->authorization->assertViewerCanJoin($voter, $battle->creatorLive);
        abort_unless($battle->status === LiveBattleStatus::ACTIVE, 422, 'This battle is not active.');

        $targetUserId = (int) $data['target_user_id'];
        abort_unless(in_array($targetUserId, [(int) $battle->creator_id, (int) $battle->opponent_id], true), 422, 'Choose a participant in this battle.');

        $created = false;
        [$battle, $transaction] = DB::transaction(function () use ($battle, $voter, $data, $targetUserId, &$created) {
            $battle = LiveBattle::query()->lockForUpdate()->findOrFail($battle->id);
            abort_unless($battle->status === LiveBattleStatus::ACTIVE, 422, 'This battle is not active.');

            $transaction = KulCoinTransaction::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($transaction) {
                abort_unless(
                    (int) $transaction->user_id === (int) $voter->id
                    && $transaction->type === 'vote'
                    && ($transaction->metadata['contest_type'] ?? null) === 'live_battle'
                    && (int) ($transaction->metadata['contest_id'] ?? 0) === (int) $battle->id
                    && (int) ($transaction->metadata['target_id'] ?? 0) === $targetUserId
                    && (int) ($transaction->metadata['vote_count'] ?? 0) === (int) $data['vote_count'],
                    422,
                    'This idempotency key has already been used for another transaction.'
                );
                return [$battle, $transaction];
            }

            $transaction = $this->kulCoins->castVote($voter, [
                'contest_type' => 'live_battle',
                'contest_id' => $battle->id,
                'target_id' => $targetUserId,
                'vote_count' => (int) $data['vote_count'],
                'idempotency_key' => $data['idempotency_key'],
            ], $voter);
            $votes = (int) ($transaction->metadata['vote_count'] ?? $data['vote_count']);
            $scoreColumn = $targetUserId === (int) $battle->creator_id ? 'creator_score' : 'opponent_score';
            $battle->increment($scoreColumn, $votes);
            $participant = $battle->participants()->firstOrCreate(
                ['user_id' => $targetUserId],
                [
                    'live_session_id' => $targetUserId === (int) $battle->creator_id
                        ? $battle->creator_live_session_id : $battle->opponent_live_session_id,
                    'side' => $targetUserId === (int) $battle->creator_id ? 'creator' : 'opponent',
                    'score' => 0,
                ]
            );
            $participant->increment('score', $votes);
            $created = true;

            return [$battle->fresh(['participants']), $transaction];
        });

        if ($created) {
            $eventData = [
                'battle' => $battle,
                'vote' => [
                    'transaction_id' => $transaction->id,
                    'target_user_id' => $targetUserId,
                    'vote_count' => (int) ($transaction->metadata['vote_count'] ?? $data['vote_count']),
                    'coin_amount' => (int) $transaction->coin_amount,
                ],
            ];
            collect([$battle->creatorLive, $battle->opponentLive])
                ->unique('id')
                ->each(fn (LiveSession $live) => LiveUpdated::dispatch($live->fresh('creator'), 'battle_vote', $eventData));
        }

        return ['battle' => $battle, 'transaction' => $transaction];
    }

    public function end(LiveBattle $battle): LiveBattle
    {
        if ($battle->status === LiveBattleStatus::ENDED) {
            return $battle;
        }

        $battle->update([
            'status' => LiveBattleStatus::ENDED,
            'ended_at' => now(),
            'winner_user_id' => $battle->creator_score === $battle->opponent_score
                ? null
                : ($battle->creator_score > $battle->opponent_score ? $battle->creator_id : $battle->opponent_id),
        ]);

        LiveUpdated::dispatch($battle->creatorLive->fresh('creator'), 'battle_end');

        return $battle->fresh();
    }
}
