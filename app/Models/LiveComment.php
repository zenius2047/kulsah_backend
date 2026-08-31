<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveComment extends Model
{
    protected $fillable = ['live_session_id', 'user_id', 'body', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
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

