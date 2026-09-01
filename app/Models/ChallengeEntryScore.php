<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeEntryScore extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'calculated_at' => 'datetime'];
    }
}
