<?php

namespace App\Services;

use App\Models\KulCoinPackage;

class KulCoinConversionService
{
    /**
     * Derive the average GHS value of one delivered coin from the active GHS
     * packages. Bonus coins are included because they are spendable too.
     */
    public function ghsPerCoin(): float
    {
        $totals = KulCoinPackage::query()
            ->where('is_active', true)
            ->where('currency_code', 'GHS')
            ->selectRaw('COALESCE(SUM(usd_price), 0) AS total_price')
            ->selectRaw('COALESCE(SUM(coin_amount + bonus_coin_amount), 0) AS total_coins')
            ->first();

        $totalCoins = (int) ($totals?->total_coins ?? 0);

        return $totalCoins > 0
            ? (float) $totals->total_price / $totalCoins
            : 0.0;
    }
}
