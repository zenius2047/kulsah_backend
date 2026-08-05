<?php

namespace Tests\Feature;

use App\Models\KulCoinGift;
use App\Models\KulCoinPackage;
use App\Models\KulCoinWallet;
use App\Models\Role;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KulCoinSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_buy_send_vote_and_receive_bonus_coins(): void
    {
        config()->set('logging.default', 'null');

        $buyer = User::factory()->create([
            'username' => 'kulcoin_buyer',
        ]);
        $creator = User::factory()->create([
            'username' => 'kulcoin_creator',
        ]);
        $admin = User::factory()->create([
            'username' => 'kulcoin_admin',
        ]);

        $role = Role::create(['name' => 'admin']);
        $admin->roles()->attach($role->id);

        $package = KulCoinPackage::create([
            'code' => 'test-pack',
            'name' => 'Test Pack',
            'coin_amount' => 100,
            'bonus_coin_amount' => 20,
            'usd_price' => 1.00,
            'currency_code' => 'USD',
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => [],
        ]);

        $gift = KulCoinGift::create([
            'code' => 'test-gift',
            'name' => 'Test Gift',
            'category' => 'romance',
            'coin_cost' => 50,
            'sort_order' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);

        $purchaseResponse = $this
            ->actingAs($buyer, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/general/kulcoin/purchase', [
                'package_id' => $package->id,
                'payment_reference' => 'pay_123',
                'local_currency' => 'USD',
                'local_amount' => 1.00,
                'usd_amount' => 1.00,
                'idempotency_key' => 'kc-purchase-1',
            ]);

        $purchaseResponse->assertCreated()
            ->assertJsonPath('data.type', 'purchase')
            ->assertJsonPath('data.coin_amount', 100)
            ->assertJsonPath('data.bonus_coin_amount', 20);

        $buyerWallet = KulCoinWallet::query()->where('user_id', $buyer->id)->firstOrFail();
        $this->assertSame(100, (int) $buyerWallet->available_balance_kc);
        $this->assertSame(20, (int) $buyerWallet->bonus_balance_kc);

        $giftResponse = $this
            ->actingAs($buyer, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/general/kulcoin/gifts/send', [
                'gift_id' => $gift->id,
                'creator_id' => $creator->id,
                'quantity' => 1,
                'message' => 'Keep going!',
                'idempotency_key' => 'kc-gift-1',
            ]);

        $giftResponse->assertCreated()
            ->assertJsonPath('data.type', 'gift')
            ->assertJsonPath('data.coin_amount', 50);

        $buyerWallet->refresh();
        $this->assertSame(50, (int) $buyerWallet->available_balance_kc);
        $this->assertSame(20, (int) $buyerWallet->bonus_balance_kc);

        $creatorUsdWallet = app(WalletService::class)->getOrCreateUserWallet($creator)->refresh();
        $this->assertSame('0.3500', (string) $creatorUsdWallet->pending_balance_usd);

        $voteResponse = $this
            ->actingAs($buyer, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/general/kulcoin/votes', [
                'contest_type' => 'dance_challenge',
                'contest_id' => 'challenge-001',
                'target_id' => $creator->id,
                'vote_count' => 3,
                'idempotency_key' => 'kc-vote-1',
            ]);

        $voteResponse->assertCreated()
            ->assertJsonPath('data.type', 'vote')
            ->assertJsonPath('data.coin_amount', 30);

        $buyerWallet->refresh();
        $this->assertSame(20, (int) $buyerWallet->available_balance_kc);
        $this->assertSame(20, (int) $buyerWallet->bonus_balance_kc);

        $bonusResponse = $this
            ->actingAs($admin, 'sanctum')
            ->withoutMiddleware(\App\Http\Middleware\RoleMiddleware::class)
            ->postJson('/api/v1/general/kulcoin/bonus', [
                'user_id' => $buyer->id,
                'coins' => 25,
                'reason' => 'Promo reward',
            ]);

        $bonusResponse->assertCreated()
            ->assertJsonPath('data.type', 'bonus')
            ->assertJsonPath('data.bonus_coin_amount', 25);

        $buyerWallet->refresh();
        $this->assertSame(20, (int) $buyerWallet->available_balance_kc);
        $this->assertSame(45, (int) $buyerWallet->bonus_balance_kc);
    }
}
