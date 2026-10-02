<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceCall extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function caller() { return $this->belongsTo(User::class, 'caller_id'); }
    public function callee() { return $this->belongsTo(User::class, 'callee_id'); }
}
