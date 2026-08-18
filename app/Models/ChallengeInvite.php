<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeInvite extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}
