<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeJudgingStage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'configuration' => 'array'];
    }
}
