<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveCohost extends Model
{
    protected $fillable = ['live_session_id', 'user_id', 'status', 'accepted_at', 'removed_at'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }
}

