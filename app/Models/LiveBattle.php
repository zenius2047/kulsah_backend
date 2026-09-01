<?php

namespace App\Models;

use App\Enums\LiveBattleStatus;
use Illuminate\Database\Eloquent\Model;

class LiveBattle extends Model
{
    protected $fillable = [
        'public_id',
        'creator_live_session_id',
        'opponent_live_session_id',
        'creator_id',
        'opponent_id',
        'status',
        'creator_score',
        'opponent_score',
        'winner_user_id',
        'invited_by_id',
        'accepted_at',
        'started_at',
        'ended_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LiveBattleStatus::class,
            'creator_score' => 'integer',
            'opponent_score' => 'integer',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function creatorLive()
    {
        return $this->belongsTo(LiveSession::class, 'creator_live_session_id');
    }

    public function opponentLive()
    {
        return $this->belongsTo(LiveSession::class, 'opponent_live_session_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function opponent()
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function participants()
    {
        return $this->hasMany(LiveBattleParticipant::class);
    }
}
