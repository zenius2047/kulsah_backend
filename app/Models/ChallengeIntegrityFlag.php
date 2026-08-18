<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeIntegrityFlag extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'resolved_at' => 'datetime'];
    }
}
