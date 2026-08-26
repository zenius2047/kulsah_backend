<?php

namespace App\Services;

use InvalidArgumentException;

class MoneyService
{
    public function toMinorUnits(string|int|float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $decimals = in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : 2;
        $normalized = number_format((float) $amount, $decimals, '.', '');

        if ((float) $normalized <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return (int) round((float) $normalized * (10 ** $decimals));
    }
}
