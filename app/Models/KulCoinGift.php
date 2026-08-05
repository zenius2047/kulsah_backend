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
        'category',
        'coin_cost',
        'sort_order',
        'is_active',
        'icon_url',
        'animation_url',
        'metadata',
    ];

    protected $casts = [
        'coin_cost' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
