<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeMedia extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }
}
