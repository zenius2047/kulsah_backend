<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_releases_pending_funds_into_available_balance(): void
    {
        config()->set('wallet.holding_period_days', 0);

        $walletService = app(WalletService::class);

        $payer = User::factory()->create([
            'username' => 'payer',
            'email' => 'payer@example.com',
        ]);

        $creator = User::factory()->create([
            'username' => 'creator',
            'email' => 'creator@example.com',
        ]);

        $payerWallet = $walletService->getOrCreateUserWallet($payer);
        $payerWallet->forceFill(['available_balance_usd' => 100])->save();

        $walletService->recordPayment(
            payer: $payer,
            creator: $creator,
            localAmount: 50,
            localCurrency: 'USD',
            fxRateUsed: 1,
            description: 'Monthly subscription',
            metadata: ['test' => true],
            actor: $payer
        );

        $creatorWallet = $walletService->getOrCreateUserWallet($creator)->refresh();

        $this->assertSame('50.0000', (string) $creatorWallet->pending_balance_usd);
        $this->assertSame('0.0000', (string) $creatorWallet->available_balance_usd);

        $summary = $walletService->settlePendingFunds();

        $creatorWallet->refresh();

        $this->assertSame(1, $summary['wallets']);
        $this->assertSame(1, $summary['entries']);
        $this->assertSame('50.0000', number_format((float) $summary['amount_usd'], 4, '.', ''));
        $this->assertSame('0.0000', (string) $creatorWallet->pending_balance_usd);
        $this->assertSame('50.0000', (string) $creatorWallet->available_balance_usd);
    }

    public function test_settlement_is_idempotent_and_does_not_release_the_same_entry_twice(): void
    {
        config()->set('wallet.holding_period_days', 0);

        $walletService = app(WalletService::class);

        $payer = User::factory()->create([
            'username' => 'payer-two',
            'email' => 'payer-two@example.com',
        ]);

        $creator = User::factory()->create([
            'username' => 'creator-two',
            'email' => 'creator-two@example.com',
        ]);

        $payerWallet = $walletService->getOrCreateUserWallet($payer);
        $payerWallet->forceFill(['available_balance_usd' => 100])->save();

        $walletService->recordPayment(
            payer: $payer,
            creator: $creator,
            localAmount: 25,
            localCurrency: 'USD',
            fxRateUsed: 1,
            description: 'Subscription payment',
            metadata: ['test' => true],
            actor: $payer
        );

        $first = $walletService->settlePendingFunds();
        $second = $walletService->settlePendingFunds();

        $creatorWallet = $walletService->getOrCreateUserWallet($creator)->refresh();

        $this->assertSame(1, $first['entries']);
        $this->assertSame(0, $second['entries']);
        $this->assertSame('25.0000', (string) $creatorWallet->available_balance_usd);
        $this->assertSame('0.0000', (string) $creatorWallet->pending_balance_usd);
    }
}
