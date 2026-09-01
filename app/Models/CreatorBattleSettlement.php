<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorBattleSettlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vote_count' => 'integer',
            'vote_coin_amount' => 'integer',
            'conversion_rate' => 'decimal:6',
            'usd_amount' => 'decimal:4',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function winner()
    {
        return $this->belongsTo(ChallengeWinner::class, 'challenge_winner_id');
    }

    public function entry()
    {
        return $this->belongsTo(ChallengeEntry::class, 'challenge_entry_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}