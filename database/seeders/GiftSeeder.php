<?php

namespace Database\Seeders;

use App\Models\CommunityPost;
use App\Models\CommunityPostGift;
use App\Models\KulCoinGift;
use App\Models\KulCoinLedgerEntry;
use App\Models\KulCoinTransaction;
use App\Models\KulCoinWallet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GiftSeeder extends Seeder
{
    public const TARGET_COUNT = 200;

    public function run(): void
    {
        DB::transaction(function (): void {
            $senders = User::query()
                ->whereIn('username', ['fan', 'fans'])
                ->orderBy('id')
                ->get()
                ->values();
            $posts = CommunityPost::query()
                ->where('content', 'like', 'Community dispatch %')
                ->orderBy('id')
                ->limit(self::TARGET_COUNT)
                ->get()
                ->values();
            $gifts = KulCoinGift::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->values();
            $treasury = KulCoinWallet::query()->where('account_key', 'kulcoin_treasury')->firstOrFail();
            $senderWallets = KulCoinWallet::query()
                ->whereIn('user_id', $senders->pluck('id'))
                ->get()
                ->keyBy('user_id');

            if ($posts->count() < self::TARGET_COUNT || $gifts->isEmpty() || $senderWallets->count() !== $senders->count()) {
                throw new \RuntimeException('GiftSeeder requires volume community posts, the gift catalog, and sender wallets.');
            }

            $runningBalances = [];
            foreach ($senders as $sender) {
                $runningBalances[$sender->id] = 100000;
            }
            $treasuryRunningBalance = 100000;
            $messages = [
                'Keep creating - the community sees the work.',
                'This post made my day.',
                'More behind-the-scenes posts like this, please.',
                'A little support for the next idea.',
                'Loved the story and the practical details.',
            ];

            for ($number = 1; $number <= self::TARGET_COUNT; $number++) {
                $post = $posts->get($number - 1);
                $sender = $senders->get(($number - 1) % $senders->count());
                $gift = $gifts->get(($number - 1) % $gifts->count());
                $senderWallet = $senderWallets->get($sender->id);
                $coinAmount = (int) $gift->coin_cost;
                $runningBalances[$sender->id] -= $coinAmount;
                $treasuryRunningBalance += $coinAmount;
                $reference = sprintf('55000000-0000-4000-8000-%012d', $number);
                $processedAt = now()->subHours(($number % 720) + 1);

                $transaction = KulCoinTransaction::query()->updateOrCreate(
                    ['reference' => $reference],
                    [
                        'idempotency_key' => sprintf('seed-volume-community-gift-%03d', $number),
                        'type' => 'gift',
                        'status' => 'completed',
                        'user_id' => $sender->id,
                        'counterparty_wallet_id' => $treasury->id,
                        'package_id' => null,
                        'gift_id' => $gift->id,
                        'local_currency' => null,
                        'local_amount' => null,
                        'usd_amount' => round($coinAmount / 100, 4),
                        'coin_amount' => $coinAmount,
                        'bonus_coin_amount' => 0,
                        'net_coin_amount' => $coinAmount,
                        'description' => sprintf('%s sent to community dispatch %03d', $gift->name, $number),
                        'metadata' => [
                            'seeded' => true,
                            'seed_group' => 'volume_community_gifts',
                            'community_post_id' => $post->id,
                            'creator_id' => $post->user_id,
                            'gift_code' => $gift->code,
                        ],
                        'performed_by_user_id' => $sender->id,
                        'processed_at' => $processedAt,
                    ],
                );

                KulCoinLedgerEntry::query()->updateOrCreate(
                    [
                        'kulcoin_transaction_id' => $transaction->id,
                        'kulcoin_wallet_id' => $senderWallet->id,
                        'entry_type' => 'debit',
                        'balance_bucket' => 'available',
                    ],
                    [
                        'amount_kc' => $coinAmount,
                        'running_balance_kc' => max(0, $runningBalances[$sender->id]),
                        'narration' => sprintf('Community gift %03d sent', $number),
                        'metadata' => ['seeded' => true, 'seed_group' => 'volume_community_gifts'],
                        'settlement_available_at' => $processedAt,
                        'settled_at' => $processedAt,
                    ],
                );
                KulCoinLedgerEntry::query()->updateOrCreate(
                    [
                        'kulcoin_transaction_id' => $transaction->id,
                        'kulcoin_wallet_id' => $treasury->id,
                        'entry_type' => 'credit',
                        'balance_bucket' => 'available',
                    ],
                    [
                        'amount_kc' => $coinAmount,
                        'running_balance_kc' => $treasuryRunningBalance,
                        'narration' => sprintf('Community gift %03d received', $number),
                        'metadata' => ['seeded' => true, 'seed_group' => 'volume_community_gifts'],
                        'settlement_available_at' => $processedAt,
                        'settled_at' => $processedAt,
                    ],
                );

                CommunityPostGift::query()->updateOrCreate(
                    ['kulcoin_transaction_id' => $transaction->id],
                    [
                        'community_post_id' => $post->id,
                        'sender_id' => $sender->id,
                        'recipient_user_id' => $post->user_id,
                        'gift_id' => $gift->id,
                        'quantity' => 1,
                        'coin_amount' => $coinAmount,
                        'message' => $messages[($number - 1) % count($messages)],
                    ],
                );
            }

            foreach ($senders as $sender) {
                $senderWallets->get($sender->id)->update([
                    'available_balance_kc' => max(0, $runningBalances[$sender->id]),
                    'last_ledger_at' => now(),
                ]);
            }
            $treasury->update([
                'available_balance_kc' => $treasuryRunningBalance,
                'last_ledger_at' => now(),
            ]);
        });
    }
}
