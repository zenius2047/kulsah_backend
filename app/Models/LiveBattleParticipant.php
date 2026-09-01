<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveBattleParticipant extends Model
{
    protected $fillable = [
        'live_battle_id',
        'live_session_id',
        'user_id',
        'side',
        'score',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function battle()
    {
        return $this->belongsTo(LiveBattle::class, 'live_battle_id');
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
