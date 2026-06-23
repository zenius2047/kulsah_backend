<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Onboarding extends Model
{
    protected $table = 'onboarding';

    protected $fillable = [
        'user_id',
        'vibe',
    ];

    protected $casts = [
        'vibe' => 'array',
    ];
}
