<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'messages' => 'boolean',
            'challenge_updates' => 'boolean',
            'live_events' => 'boolean',
            'commerce' => 'boolean',
            'marketing' => 'boolean',
            'quiet_hours' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
