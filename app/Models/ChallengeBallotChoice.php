<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeBallotChoice extends Model
{
    protected $guarded = ['id'];

    public function ballot()
    {
        return $this->belongsTo(ChallengeBallot::class);
    }

    public function entry()
    {
        return $this->belongsTo(ChallengeEntry::class, 'challenge_entry_id');
    }
}
