<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveViewerSession extends Model
{
    protected $fillable = ['live_session_id', 'user_id', 'session_key', 'joined_at', 'left_at', 'watch_seconds', 'last_heartbeat_at'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'watch_seconds' => 'integer',
        ];
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

