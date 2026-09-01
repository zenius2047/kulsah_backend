<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeWinner extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'confirmed_at' => 'datetime', 'replaced_at' => 'datetime'];
    }

    public function entry()
    {
        return $this->belongsTo(ChallengeEntry::class, 'challenge_entry_id');
    }

    public function allocations()
    {
        return $this->hasMany(ChallengeRewardAllocation::class, 'winner_id');
    }
}
