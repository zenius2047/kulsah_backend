<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeSelectionDecision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function entry()
    {
        return $this->belongsTo(ChallengeEntry::class, 'challenge_entry_id');
    }
}
