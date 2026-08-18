<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeJuryCriterion extends Model
{
    protected $guarded = ['id'];

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}
