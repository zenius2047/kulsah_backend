<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickerRecent extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['last_used_at' => 'datetime']; }
    public function sticker() { return $this->belongsTo(Sticker::class); }
}
