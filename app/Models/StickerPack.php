<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StickerPack extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_official' => 'boolean', 'is_public' => 'boolean', 'is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function stickers() { return $this->hasMany(Sticker::class); }
}
