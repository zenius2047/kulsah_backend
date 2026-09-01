<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessageReaction extends Model
{
    protected $guarded = ['id'];

    public function message()
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
