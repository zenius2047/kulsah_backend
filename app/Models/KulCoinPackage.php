<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KulCoinPackage extends Model
{
    protected $table = 'kulcoin_packages';

    protected $fillable = [
        'code',
        'name',
        'coin_amount',
        'bonus_coin_amount',
        'usd_price',
        'currency_code',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'coin_amount' => 'integer',
        'bonus_coin_amount' => 'integer',
        'usd_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
