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
            'signal_allow_contact_sync' => 'boolean',
            'signal_discoverable_by_phone' => 'boolean',
            'signal_discoverable_by_search' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
