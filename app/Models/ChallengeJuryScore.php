<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeJuryScore extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'submitted_at' => 'datetime'];
    }

    public function entry()
    {
        return $this->belongsTo(ChallengeEntry::class, 'challenge_entry_id');
    }

    public function criterion()
    {
        return $this->belongsTo(ChallengeJuryCriterion::class, 'criterion_id');
    }

    public function juryMember()
    {
        return $this->belongsTo(ChallengeJuryMember::class);
    }
}
