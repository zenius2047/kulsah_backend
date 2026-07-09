<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KulCoinGift extends Model
{
    protected $table = 'kulcoin_gifts';

    protected $fillable = [
        'code',
        'name',
        'coin_cost',
        'is_active',
        'icon_url',
        'animation_url',
        'metadata',
    ];

    protected $casts = [
        'coin_cost' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
