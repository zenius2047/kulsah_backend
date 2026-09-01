<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeRewardPool extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['funded_at' => 'datetime'];
    }
}
