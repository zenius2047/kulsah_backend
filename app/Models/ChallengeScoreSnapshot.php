<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeScoreSnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['components' => 'array', 'captured_at' => 'datetime'];
    }
}
