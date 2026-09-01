<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessageRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'intro_metadata' => 'array',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'blocked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
