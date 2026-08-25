<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'archived_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
