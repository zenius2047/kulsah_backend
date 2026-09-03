<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            KulCoinCatalogSeeder::class,
            GhsKulCoinCatalogSeeder::class,
            StickerSeeder::class,
            WalletSeeder::class,
            DeveloperSeeder::class,
            FeedSeeder::class,
            VideoLifecycleSeeder::class,
            DuetSeeder::class,
            ProfileSubscriptionSeeder::class,
            EventSeeder::class,
            CommunitySeeder::class,
            CommunityVolumeSeeder::class,
            GiftSeeder::class,
            PlaylistSeeder::class,
            NotificationSeeder::class,
            ChallengeSeeder::class,
            ChallengeVolumeSeeder::class,
        ]);
    }
}
