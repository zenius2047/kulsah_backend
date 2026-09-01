<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sticker extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'is_animated' => 'boolean', 'is_active' => 'boolean'];
    }

    public function pack() { return $this->belongsTo(StickerPack::class, 'sticker_pack_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
}
