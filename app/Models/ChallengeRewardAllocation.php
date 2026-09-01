<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeRewardAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'allocated_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function winner()
    {
        return $this->belongsTo(ChallengeWinner::class, 'winner_id');
    }

    public function prize()
    {
        return $this->belongsTo(ChallengePrize::class, 'prize_id');
    }
}
