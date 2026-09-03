<?php

return [
    // Wallet amounts currently use GHS; additional base currencies can be added later.
    'base_currency' => strtoupper(env('WALLET_BASE_CURRENCY', 'GHS')),
    'holding_period_days' => (int) env('WALLET_HOLDING_PERIOD_DAYS', 7),
];