<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveModerationAction extends Model
{
    protected $fillable = ['live_session_id', 'actor_id', 'target_id', 'action', 'reason', 'duration_seconds', 'expires_at'];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function target()
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}

