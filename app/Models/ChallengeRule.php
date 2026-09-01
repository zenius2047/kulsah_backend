<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_required' => 'boolean'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}
