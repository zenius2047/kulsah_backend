<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessageAttachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'attached_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message()
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
