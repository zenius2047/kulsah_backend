<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeCollaborator extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
