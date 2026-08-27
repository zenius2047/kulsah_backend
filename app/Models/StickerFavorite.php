<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickerFavorite extends Model
{
    protected $guarded = ['id'];
    public function sticker() { return $this->belongsTo(Sticker::class); }
}
