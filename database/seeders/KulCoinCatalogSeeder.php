<?php

namespace Database\Seeders;

use App\Models\KulCoinGift;
use App\Models\KulCoinPackage;
use Illuminate\Database\Seeder;

class KulCoinCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['code' => 'kc-50', 'name' => 'Starter Pack', 'coin_amount' => 50, 'bonus_coin_amount' => 0, 'usd_price' => 0.50, 'sort_order' => 10],
            ['code' => 'kc-100', 'name' => 'Lite Pack', 'coin_amount' => 100, 'bonus_coin_amount' => 0, 'usd_price' => 1.00, 'sort_order' => 20],
            ['code' => 'kc-200', 'name' => 'Creator Pack', 'coin_amount' => 200, 'bonus_coin_amount' => 0, 'usd_price' => 2.00, 'sort_order' => 30],
            ['code' => 'kc-500', 'name' => 'Boost Pack', 'coin_amount' => 500, 'bonus_coin_amount' => 25, 'usd_price' => 5.00, 'sort_order' => 40],
            ['code' => 'kc-1000', 'name' => 'Pro Pack', 'coin_amount' => 1000, 'bonus_coin_amount' => 100, 'usd_price' => 10.00, 'sort_order' => 50],
            ['code' => 'kc-2000', 'name' => 'Elite Pack', 'coin_amount' => 2000, 'bonus_coin_amount' => 250, 'usd_price' => 20.00, 'sort_order' => 60],
            ['code' => 'kc-3000', 'name' => 'Mega Pack', 'coin_amount' => 3000, 'bonus_coin_amount' => 400, 'usd_price' => 30.00, 'sort_order' => 70],
            ['code' => 'kc-4000', 'name' => 'Ultra Pack', 'coin_amount' => 4000, 'bonus_coin_amount' => 600, 'usd_price' => 40.00, 'sort_order' => 80],
            ['code' => 'kc-5000', 'name' => 'Champion Pack', 'coin_amount' => 5000, 'bonus_coin_amount' => 800, 'usd_price' => 50.00, 'sort_order' => 90],
            ['code' => 'kc-5999', 'name' => 'Max Pack', 'coin_amount' => 5999, 'bonus_coin_amount' => 1001, 'usd_price' => 100.00, 'sort_order' => 100],
        ];

        foreach ($packages as $package) {
            KulCoinPackage::updateOrCreate(
                ['code' => $package['code']],
                $package + ['currency_code' => 'USD', 'is_active' => true, 'metadata' => []]
            );
        }

        $gifts = [
            ['code' => 'rose', 'name' => 'Rose', 'coin_cost' => 50, 'sort_order' => 10],
            ['code' => 'heart', 'name' => 'Heart', 'coin_cost' => 100, 'sort_order' => 20],
            ['code' => 'fire', 'name' => 'Fire', 'coin_cost' => 200, 'sort_order' => 30],
            ['code' => 'trophy', 'name' => 'Trophy', 'coin_cost' => 500, 'sort_order' => 40],
            ['code' => 'crown', 'name' => 'Crown', 'coin_cost' => 1000, 'sort_order' => 50],
            ['code' => 'diamond', 'name' => 'Diamond', 'coin_cost' => 2000, 'sort_order' => 60],
            ['code' => 'super-star', 'name' => 'Super Star', 'coin_cost' => 5999, 'sort_order' => 70],
        ];

        foreach ($gifts as $gift) {
            KulCoinGift::updateOrCreate(
                ['code' => $gift['code']],
                $gift + ['is_active' => true, 'metadata' => []]
            );
        }
    }
}
