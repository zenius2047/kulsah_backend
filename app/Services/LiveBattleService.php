<?php

namespace App\Services;

use App\Enums\LiveBattleStatus;
use App\Events\LiveUpdated;
use App\Models\LiveBattle;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveBattleService
{
    public function __construct(private readonly LiveAuthorizationService $authorization)
    {
    }

    public function invite(LiveSession $creatorLive, LiveSession $opponentLive, User $actor): LiveBattle
    {
        if ((int) $creatorLive->creator_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['battle' => 'Only the creator can invite another Live to a battle.']);
        }

        return LiveBattle::query()->create([
            'public_id' => (string) Str::uuid(),
            'creator_live_session_id' => $creatorLive->id,
            'opponent_live_session_id' => $opponentLive->id,
            'creator_id' => $creatorLive->creator_id,
            'opponent_id' => $opponentLive->creator_id,
            'status' => LiveBattleStatus::PENDING,
            'creator_score' => 0,
            'opponent_score' => 0,
            'invited_by_id' => $actor->id,
            'metadata' => [],
        ]);
    }

    public function accept(LiveBattle $battle, User $actor): LiveBattle
    {
        if ((int) $battle->opponent_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['battle' => 'Only the invited creator can accept this battle.']);
        }

        if (! $battle->status->canTransitionTo(LiveBattleStatus::ACCEPTED)) {
            throw ValidationException::withMessages(['battle' => 'This battle cannot be accepted.']);
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
        $battle->loadMissing(['creatorLive', 'opponentLive']);

        // Scores are derived from verified Live gift totals, never from client input.
        $creatorScore = (int) $battle->creatorLive->gift_value_kc;
        $opponentScore = (int) $battle->opponentLive->gift_value_kc;

        $battle->update([
            'creator_score' => max(0, $creatorScore),
            'opponent_score' => max(0, $opponentScore),
        ]);

        LiveUpdated::dispatch($battle->creatorLive->fresh('creator'), 'battle_score');

        return $battle->fresh();
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

