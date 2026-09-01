<?php

namespace Database\Seeders;

use App\Models\KulCoinPackage;
use Illuminate\Database\Seeder;

class GhsKulCoinCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Test catalog for a Ghana Paystack merchant. Replace prices with approved business prices before production.
        $packages = [
            ['code' => 'kc-50', 'name' => 'Starter Pack', 'coin_amount' => 50, 'bonus_coin_amount' => 0, 'price' => 5.00, 'sort_order' => 10],
            ['code' => 'kc-100', 'name' => 'Lite Pack', 'coin_amount' => 100, 'bonus_coin_amount' => 0, 'price' => 10.00, 'sort_order' => 20],
            ['code' => 'kc-200', 'name' => 'Creator Pack', 'coin_amount' => 200, 'bonus_coin_amount' => 0, 'price' => 20.00, 'sort_order' => 30],
            ['code' => 'kc-500', 'name' => 'Boost Pack', 'coin_amount' => 500, 'bonus_coin_amount' => 25, 'price' => 50.00, 'sort_order' => 40],
            ['code' => 'kc-1000', 'name' => 'Pro Pack', 'coin_amount' => 1000, 'bonus_coin_amount' => 100, 'price' => 100.00, 'sort_order' => 50],
            ['code' => 'kc-2000', 'name' => 'Elite Pack', 'coin_amount' => 2000, 'bonus_coin_amount' => 250, 'price' => 200.00, 'sort_order' => 60],
            ['code' => 'kc-3000', 'name' => 'Mega Pack', 'coin_amount' => 3000, 'bonus_coin_amount' => 400, 'price' => 300.00, 'sort_order' => 70],
            ['code' => 'kc-4000', 'name' => 'Ultra Pack', 'coin_amount' => 4000, 'bonus_coin_amount' => 600, 'price' => 400.00, 'sort_order' => 80],
            ['code' => 'kc-5000', 'name' => 'Champion Pack', 'coin_amount' => 5000, 'bonus_coin_amount' => 800, 'price' => 500.00, 'sort_order' => 90],
            ['code' => 'kc-5999', 'name' => 'Max Pack', 'coin_amount' => 5999, 'bonus_coin_amount' => 1001, 'price' => 1000.00, 'sort_order' => 100],
        ];

        foreach ($packages as $package) {
            KulCoinPackage::updateOrCreate(
                ['code' => $package['code']],
                [
                    'name' => $package['name'],
                    'coin_amount' => $package['coin_amount'],
                    'bonus_coin_amount' => $package['bonus_coin_amount'],
                    'usd_price' => $package['price'],
                    'currency_code' => 'GHS',
                    'is_active' => true,
                    'sort_order' => $package['sort_order'],
                    'metadata' => ['catalog_currency' => 'GHS', 'catalog_type' => 'paystack_test'],
                ]
            );
        }
    }
}
