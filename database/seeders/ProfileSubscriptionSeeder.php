<?php

namespace Database\Seeders;

use App\Models\Onboarding;
use App\Models\Subscription;
use App\Models\SubscriptionAction;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfileSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()
                ->whereIn('username', [
                    'admin', 'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                    'naledi.fit', 'kwame.frames', 'amina.designs',
                ])
                ->get()
                ->keyBy('username');

            $vibes = [
                'admin' => ['culture', 'technology'],
                'fan' => ['dance', 'music', 'food', 'comedy'],
                'fans' => ['fashion', 'travel', 'challenge'],
                'creator' => ['dance', 'fashion', 'music'],
                'zuri.moves' => ['dance', 'fitness', 'music'],
                'tunde.creates' => ['comedy', 'storytelling', 'music'],
                'naledi.fit' => ['fitness', 'sports', 'adventure'],
                'kwame.frames' => ['travel', 'storytelling', 'photography'],
                'amina.designs' => ['design', 'education', 'lifestyle'],
            ];

            foreach ($vibes as $username => $userVibes) {
                Onboarding::query()->updateOrCreate(
                    ['user_id' => $users->get($username)->id],
                    ['vibe' => $userVibes],
                );
            }

            $plans = [
                'creator' => ['Ama All Access', 'Dance tutorials, subscriber posts, and behind-the-scenes edits.', 18.00, 'GHS'],
                'zuri.moves' => ['Move With Zuri', 'Weekly choreography breakdowns and early challenge access.', 450.00, 'KES'],
                'tunde.creates' => ['Tunde Backstage', 'Sketch outtakes, live writing rooms, and creator notes.', 3500.00, 'NGN'],
                'naledi.fit' => ['Naledi Training Club', 'Monthly training plans and subscriber-only sessions.', 79.00, 'ZAR'],
                'kwame.frames' => ['Frames Field Notes', 'Travel guides, camera notes, and full story cuts.', 22.00, 'GHS'],
                'amina.designs' => ['Amina Home Lab', 'Room plans, sourcing tips, and detailed walkthroughs.', 3000.00, 'XOF'],
            ];

            $planModels = collect();

            foreach ($plans as $username => [$name, $description, $price, $currency]) {
                $planModels->put($username, SubscriptionPlan::query()->updateOrCreate(
                    ['creator_id' => $users->get($username)->id],
                    [
                        'name' => $name,
                        'description' => $description,
                        'price' => $price,
                        'currency' => $currency,
                        'billing_interval' => 'monthly',
                        'is_active' => true,
                    ],
                ));
            }

            $relationships = [
                ['fan', 'creator'],
                ['fan', 'zuri.moves'],
                ['fan', 'tunde.creates'],
                ['fans', 'creator'],
                ['fans', 'kwame.frames'],
                ['fans', 'amina.designs'],
            ];

            foreach ($relationships as [$subscriberUsername, $creatorUsername]) {
                $subscriber = $users->get($subscriberUsername);
                $creator = $users->get($creatorUsername);
                $plan = $planModels->get($creatorUsername);
                $subscription = Subscription::query()->updateOrCreate(
                    [
                        'subscriber_id' => $subscriber->id,
                        'creator_id' => $creator->id,
                    ],
                    [
                        'subscription_plan_id' => $plan->id,
                        'status' => 'active',
                        'starts_at' => now()->subDays(14),
                        'expires_at' => now()->addDays(16),
                        'blocked_at' => null,
                        'blocked_by' => null,
                        'blocked_reason' => null,
                    ],
                );

                SubscriptionAction::query()->updateOrCreate(
                    [
                        'subscription_id' => $subscription->id,
                        'action' => 'subscribed',
                    ],
                    [
                        'creator_id' => $creator->id,
                        'subscriber_id' => $subscriber->id,
                        'subscription_plan_id' => $plan->id,
                        'reason' => 'Demo subscription created by the project seeder.',
                        'requires_admin_review' => false,
                        'review_status' => 'not_required',
                        'metadata' => ['seeded' => true, 'source' => 'ProfileSubscriptionSeeder'],
                    ],
                );
            }

            $lifecycleRelationships = [
                [
                    'subscriber' => 'fan',
                    'creator' => 'kwame.frames',
                    'status' => 'expired',
                    'starts_at' => now()->subDays(62),
                    'expires_at' => now()->subDays(32),
                    'action' => 'subscription_expired',
                    'reason' => 'Demo subscription retained to exercise renewal and expired-access states.',
                    'requires_admin_review' => false,
                ],
                [
                    'subscriber' => 'fans',
                    'creator' => 'tunde.creates',
                    'status' => 'cancelled',
                    'starts_at' => now()->subDays(25),
                    'expires_at' => now()->addDays(5),
                    'action' => 'subscription_cancelled',
                    'reason' => 'Demo cancellation that preserves access through the paid period.',
                    'requires_admin_review' => false,
                ],
                [
                    'subscriber' => 'tunde.creates',
                    'creator' => 'creator',
                    'status' => 'blocked',
                    'starts_at' => now()->subDays(20),
                    'expires_at' => now()->addDays(10),
                    'action' => 'block_user',
                    'reason' => 'Seeded moderation example for creator subscription-management screens.',
                    'requires_admin_review' => true,
                ],
            ];

            foreach ($lifecycleRelationships as $definition) {
                $subscriber = $users->get($definition['subscriber']);
                $creator = $users->get($definition['creator']);
                $plan = $planModels->get($definition['creator']);
                $isBlocked = $definition['status'] === 'blocked';

                $subscription = Subscription::query()->updateOrCreate(
                    [
                        'subscriber_id' => $subscriber->id,
                        'creator_id' => $creator->id,
                    ],
                    [
                        'subscription_plan_id' => $plan->id,
                        'status' => $definition['status'],
                        'starts_at' => $definition['starts_at'],
                        'expires_at' => $definition['expires_at'],
                        'blocked_at' => $isBlocked ? now()->subDays(2) : null,
                        'blocked_by' => $isBlocked ? $creator->id : null,
                        'blocked_reason' => $isBlocked ? $definition['reason'] : null,
                    ],
                );

                SubscriptionAction::query()->updateOrCreate(
                    [
                        'subscription_id' => $subscription->id,
                        'action' => $definition['action'],
                    ],
                    [
                        'creator_id' => $creator->id,
                        'subscriber_id' => $subscriber->id,
                        'subscription_plan_id' => $plan->id,
                        'reason' => $definition['reason'],
                        'requires_admin_review' => $definition['requires_admin_review'],
                        'review_status' => $definition['requires_admin_review'] ? 'pending' : 'not_required',
                        'metadata' => [
                            'seeded' => true,
                            'source' => 'ProfileSubscriptionSeeder',
                            'status' => $definition['status'],
                        ],
                    ],
                );
            }
        });
    }
}
