<?php

namespace Database\Seeders;

use App\Models\KulCoinGift;
use App\Models\KulCoinLedgerEntry;
use App\Models\KulCoinPackage;
use App\Models\KulCoinTransaction;
use App\Models\KulCoinWallet;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'admin', 'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');

            $wallets = $this->seedCashWallets($users);
            $this->seedCashTransactions($users, $wallets);

            $kulCoinWallets = $this->seedKulCoinWallets($users);
            $this->seedKulCoinTransactions($users, $kulCoinWallets);
        });
    }

    private function seedCashWallets($users)
    {
        $wallets = collect();
        $systemWallets = [
            'platform' => ['Kulsah Platform Wallet', 25000.00],
            'challenge_rewards_fund' => ['Challenge Rewards Fund', 10000.00],
            'kulcoin_creator_earnings_pool' => ['KulCoin Creator Earnings Pool', 5000.00],
        ];

        foreach ($systemWallets as $accountKey => [$accountName, $balance]) {
            $wallets->put($accountKey, Wallet::query()->updateOrCreate(
                ['account_key' => $accountKey],
                [
                    'user_id' => null,
                    'account_name' => $accountName,
                    'base_currency' => config('wallet.base_currency', 'GHS'),
                    'available_balance_usd' => $balance,
                    'pending_balance_usd' => 0,
                    'held_balance_usd' => 0,
                    'status' => 'active',
                    'last_ledger_at' => now()->subDay(),
                ],
            ));
        }

        $balances = [
            'admin' => [250, 0], 'fan' => [42.50, 0], 'fans' => [18.75, 0],
            'creator' => [325.40, 34.20], 'zuri.moves' => [178.00, 22.00],
            'tunde.creates' => [411.25, 65.00], 'naledi.fit' => [294.80, 17.50],
            'kwame.frames' => [536.00, 0], 'amina.designs' => [247.50, 12.00],
        ];

        foreach ($balances as $username => [$available, $pending]) {
            $user = $users->get($username);
            $wallets->put($username, Wallet::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'account_key' => null,
                    'account_name' => "{$user->name} Wallet",
                    'base_currency' => config('wallet.base_currency', 'GHS'),
                    'available_balance_usd' => $available,
                    'pending_balance_usd' => $pending,
                    'held_balance_usd' => 0,
                    'status' => 'active',
                    'last_ledger_at' => now()->subHours(6),
                ],
            ));
        }

        return $wallets;
    }

    private function seedCashTransactions($users, $wallets): void
    {
        $deposit = WalletTransaction::query()->updateOrCreate(
            ['reference' => '11000000-0000-4000-8000-000000000001'],
            [
                'type' => 'deposit',
                'status' => 'completed',
                'user_id' => $users->get('fan')->id,
                'local_currency' => 'GHS',
                'local_amount' => 680.00,
                'usd_amount' => 42.50,
                'fx_rate_used' => 16.00,
                'platform_fee_usd' => 0,
                'processor_fee_usd' => 0,
                'net_usd_amount' => 42.50,
                'description' => 'Demo wallet top-up',
                'metadata' => ['seeded' => true, 'payment_method' => 'mobile_money'],
                'performed_by_user_id' => $users->get('fan')->id,
                'processed_at' => now()->subDays(7),
            ],
        );
        $this->cashEntry($deposit, $wallets->get('fan'), 'credit', 'available', 42.50, 42.50, 'Demo wallet top-up');

        $battleReward = WalletTransaction::query()->updateOrCreate(
            ['reference' => '11000000-0000-4000-8000-000000000002'],
            [
                'type' => 'creator_battle_settlement',
                'status' => 'completed',
                'user_id' => $users->get('kwame.frames')->id,
                'counterparty_wallet_id' => $wallets->get('challenge_rewards_fund')->id,
                'usd_amount' => 120.00,
                'platform_fee_usd' => 0,
                'processor_fee_usd' => 0,
                'net_usd_amount' => 120.00,
                'description' => 'Demo Creator Battle payout',
                'metadata' => ['seeded' => true, 'settlement' => 'creator_battle'],
                'performed_by_user_id' => $users->get('admin')->id,
                'processed_at' => now()->subDays(4),
            ],
        );
        $this->cashEntry($battleReward, $wallets->get('challenge_rewards_fund'), 'debit', 'available', 120, 10000, 'Creator Battle payout');
        $this->cashEntry($battleReward, $wallets->get('kwame.frames'), 'credit', 'available', 120, 536, 'Creator Battle winnings');
    }

    private function cashEntry(
        WalletTransaction $transaction,
        Wallet $wallet,
        string $entryType,
        string $bucket,
        float $amount,
        float $runningBalance,
        string $narration,
    ): void {
        WalletLedgerEntry::query()->updateOrCreate(
            [
                'wallet_transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'entry_type' => $entryType,
                'balance_bucket' => $bucket,
            ],
            [
                'amount_usd' => $amount,
                'running_balance_usd' => $runningBalance,
                'narration' => $narration,
                'metadata' => ['seeded' => true],
                'settlement_available_at' => now()->subDays(3),
                'settled_at' => now()->subDays(3),
            ],
        );
    }

    private function seedKulCoinWallets($users)
    {
        $wallets = collect();
        foreach ([
            'kulcoin_issuer' => 'KulCoin Issuer Wallet',
            'kulcoin_promo_pool' => 'KulCoin Promotion Pool',
            'kulcoin_treasury' => 'KulCoin Treasury Wallet',
        ] as $accountKey => $accountName) {
            $wallets->put($accountKey, KulCoinWallet::query()->updateOrCreate(
                ['account_key' => $accountKey],
                [
                    'user_id' => null,
                    'account_name' => $accountName,
                    'currency_code' => 'KC',
                    'available_balance_kc' => $accountKey === 'kulcoin_treasury' ? 18250 : 0,
                    'bonus_balance_kc' => 0,
                    'status' => 'active',
                    'last_ledger_at' => now()->subHour(),
                ],
            ));
        }

        $balances = [
            'admin' => [5000, 0], 'fan' => [1350, 100], 'fans' => [820, 50],
            'creator' => [2400, 200], 'zuri.moves' => [1950, 150],
            'tunde.creates' => [2100, 100], 'naledi.fit' => [1750, 100],
            'kwame.frames' => [2650, 250], 'amina.designs' => [1880, 120],
        ];
        foreach ($balances as $username => [$available, $bonus]) {
            $user = $users->get($username);
            $wallets->put($username, KulCoinWallet::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'account_key' => null,
                    'account_name' => "{$user->name} KulCoin Wallet",
                    'currency_code' => 'KC',
                    'available_balance_kc' => $available,
                    'bonus_balance_kc' => $bonus,
                    'status' => 'active',
                    'last_ledger_at' => now()->subHour(),
                ],
            ));
        }

        return $wallets;
    }

    private function seedKulCoinTransactions($users, $wallets): void
    {
        $package = KulCoinPackage::query()->where('code', 'kc-1000')->firstOrFail();
        $purchase = KulCoinTransaction::query()->updateOrCreate(
            ['reference' => '22000000-0000-4000-8000-000000000001'],
            [
                'idempotency_key' => 'seed-kc-purchase-fan-001',
                'type' => 'purchase',
                'status' => 'completed',
                'user_id' => $users->get('fan')->id,
                'counterparty_wallet_id' => $wallets->get('kulcoin_issuer')->id,
                'package_id' => $package->id,
                'local_currency' => 'USD',
                'local_amount' => 10,
                'usd_amount' => 10,
                'coin_amount' => 1000,
                'bonus_coin_amount' => 100,
                'net_coin_amount' => 1100,
                'description' => 'Demo Pro Pack purchase',
                'metadata' => ['seeded' => true],
                'performed_by_user_id' => $users->get('fan')->id,
                'processed_at' => now()->subDays(6),
            ],
        );
        $this->kulCoinEntry($purchase, $wallets->get('kulcoin_issuer'), 'debit', 'available', 1000, 0, 'KC package issuance');
        $this->kulCoinEntry($purchase, $wallets->get('fan'), 'credit', 'available', 1000, 1350, 'KC package purchase');
        $this->kulCoinEntry($purchase, $wallets->get('kulcoin_promo_pool'), 'debit', 'bonus', 100, 0, 'Promotional bonus issuance');
        $this->kulCoinEntry($purchase, $wallets->get('fan'), 'credit', 'bonus', 100, 100, 'Promotional bonus coins');

        $gift = KulCoinGift::query()->where('code', 'rose')->firstOrFail();
        $giftTransaction = KulCoinTransaction::query()->updateOrCreate(
            ['reference' => '22000000-0000-4000-8000-000000000002'],
            [
                'idempotency_key' => 'seed-kc-gift-community-001',
                'type' => 'gift',
                'status' => 'completed',
                'user_id' => $users->get('fan')->id,
                'counterparty_wallet_id' => $wallets->get('kulcoin_treasury')->id,
                'gift_id' => $gift->id,
                'usd_amount' => 0.35,
                'coin_amount' => 50,
                'bonus_coin_amount' => 0,
                'net_coin_amount' => 50,
                'description' => 'Rose gift',
                'metadata' => [
                    'seeded' => true,
                    'creator_id' => $users->get('creator')->id,
                    'gift_code' => 'rose',
                ],
                'performed_by_user_id' => $users->get('fan')->id,
                'processed_at' => now()->subDays(2),
            ],
        );
        $this->kulCoinEntry($giftTransaction, $wallets->get('fan'), 'debit', 'available', 50, 1350, 'Gift purchase');
        $this->kulCoinEntry($giftTransaction, $wallets->get('kulcoin_treasury'), 'credit', 'available', 50, 18250, 'Gift received by treasury');

        $vote = KulCoinTransaction::query()->updateOrCreate(
            ['reference' => '22000000-0000-4000-8000-000000000003'],
            [
                'idempotency_key' => 'seed-kc-battle-vote-001',
                'type' => 'challenge_vote',
                'status' => 'completed',
                'user_id' => $users->get('fans')->id,
                'counterparty_wallet_id' => $wallets->get('kulcoin_treasury')->id,
                'usd_amount' => 0.10,
                'coin_amount' => 10,
                'bonus_coin_amount' => 0,
                'net_coin_amount' => 10,
                'description' => 'Demo Creator Battle vote',
                'metadata' => ['seeded' => true, 'vote_count' => 1],
                'performed_by_user_id' => $users->get('fans')->id,
                'processed_at' => now()->subHours(3),
            ],
        );
        $this->kulCoinEntry($vote, $wallets->get('fans'), 'debit', 'available', 10, 820, 'Creator Battle vote');
        $this->kulCoinEntry($vote, $wallets->get('kulcoin_treasury'), 'credit', 'available', 10, 18250, 'Creator Battle vote received');
    }

    private function kulCoinEntry(
        KulCoinTransaction $transaction,
        KulCoinWallet $wallet,
        string $entryType,
        string $bucket,
        int $amount,
        int $runningBalance,
        string $narration,
    ): void {
        KulCoinLedgerEntry::query()->updateOrCreate(
            [
                'kulcoin_transaction_id' => $transaction->id,
                'kulcoin_wallet_id' => $wallet->id,
                'entry_type' => $entryType,
                'balance_bucket' => $bucket,
            ],
            [
                'amount_kc' => $amount,
                'running_balance_kc' => $runningBalance,
                'narration' => $narration,
                'metadata' => ['seeded' => true],
                'settlement_available_at' => now()->subDay(),
                'settled_at' => now()->subDay(),
            ],
        );
    }
}
