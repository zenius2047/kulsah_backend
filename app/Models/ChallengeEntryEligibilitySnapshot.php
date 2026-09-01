<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeEntryEligibilitySnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'evaluation' => 'array', 'evaluated_at' => 'datetime'];
    }
}
