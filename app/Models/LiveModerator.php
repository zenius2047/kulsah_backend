<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveModerator extends Model
{
    protected $fillable = ['live_session_id', 'user_id', 'appointed_by', 'removed_at'];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
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

    public function appointedBy()
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }
}

