<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_group' => 'boolean',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function activeParticipants()
    {
        return $this->participants()->whereNull('archived_at');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function lastMessage()
    {
        return $this->belongsTo(ConversationMessage::class, 'last_message_id');
    }
}
