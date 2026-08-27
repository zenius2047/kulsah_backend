<?php

return [
    'currency_code' => env('KULCOIN_CURRENCY_CODE', 'KC'),
    'coin_to_usd_rate' => (float) env('KULCOIN_COIN_TO_USD_RATE', 0.01),
    'creator_share_percent' => (int) env('KULCOIN_CREATOR_SHARE_PERCENT', 70),
    'vote_coin_price' => (int) env('KULCOIN_VOTE_COIN_PRICE', 10),
    'issuer_account_key' => env('KULCOIN_ISSUER_ACCOUNT_KEY', 'kulcoin_issuer'),
    'promo_account_key' => env('KULCOIN_PROMO_ACCOUNT_KEY', 'kulcoin_promo_pool'),
    'treasury_account_key' => env('KULCOIN_TREASURY_ACCOUNT_KEY', 'kulcoin_treasury'),
    // GHS is the current product currency; keep this configurable for future markets.
    'default_package_currency' => strtoupper(env('KULCOIN_DEFAULT_PACKAGE_CURRENCY', 'GHS')),
    'wallet_status' => env('KULCOIN_WALLET_STATUS', 'active'),
];

