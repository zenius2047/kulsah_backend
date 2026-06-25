<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\SubscriptionAction;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    private const CACHE_TTL_MINUTES = 10;
    private const RENEWAL_WINDOW_DAYS = 7;

    // Return the authenticated creator's own plans, cached in Redis for a short window.
    public function index(Request $request)
    {
        [$plans, $cacheHit] = $this->getCreatorPrivatePlans($request);

        return response()->json([
            'data' => $plans,
            'meta' => [
                'cache_hit' => $cacheHit,
                'cache_key' => $this->creatorPrivatePlansCacheKey($request->user()->id),
            ],
        ]);
    }

    // Return any creator's public plans, also cached so fan-facing reads stay fast.
    public function showCreatorPlans(Request $request, User $creator)
    {
        [$plans, $cacheHit] = $this->getCreatorPublicPlans($creator, $request);

        return response()->json([
            'data' => $plans,
            'meta' => [
                'cache_hit' => $cacheHit,
                'cache_key' => $this->creatorPublicPlansCacheKey($creator->id),
            ],
        ]);
    }

    // Creators can create only one plan; after that they should update the existing plan instead.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'billing_interval' => ['required', 'string', 'in:monthly'],
        ]);

        abort_if(
            $request->user()->subscriptionPlans()->exists(),
            422,
            'You already have a subscription plan. Please update your existing plan instead of creating a new one.'
        );

        $plan = DB::transaction(function () use ($request, $validated) {
            return $request->user()
                ->subscriptionPlans()
                ->create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'price' => $validated['price'],
                    'currency' => $validated['currency'],
                    'billing_interval' => $validated['billing_interval'],
                    'is_active' => true,
                ]);
        });

        $plan->load('creator:id,name,username,avatar');
        $this->forgetCreatorPlansCache($request->user()->id);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'data' => (new SubscriptionPlanResource($plan))->toArray($request),
        ], 201);
    }

    // Only the plan owner can update the plan details.
    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $this->ensureOwnership($request->user(), $subscriptionPlan);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'billing_interval' => ['sometimes', 'string', 'in:monthly'],
        ]);

        DB::transaction(function () use ($subscriptionPlan, $validated) {
            $subscriptionPlan->update($validated);
        });

        $subscriptionPlan->refresh();
        $subscriptionPlan->load('creator:id,name,username,avatar');
        $this->forgetCreatorPlansCache($subscriptionPlan->creator_id);

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'data' => (new SubscriptionPlanResource($subscriptionPlan))->toArray($request),
        ]);
    }

    // Fans subscribe to an active plan; re-subscription is only allowed near expiry or after expiry.
    public function subscribe(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        abort_unless($subscriptionPlan->is_active, 422, 'Selected subscription plan is inactive.');
        abort_if((string) $request->user()->id === (string) $subscriptionPlan->creator_id, 422, 'You cannot subscribe to your own plan.');

        $existingSubscription = Subscription::query()
            ->where('subscriber_id', $request->user()->id)
            ->where('creator_id', $subscriptionPlan->creator_id)
            ->first();

        if ($existingSubscription?->status === 'blocked') {
            abort(403, 'You have been blocked from this creator.');
        }

        $subscription = DB::transaction(function () use ($request, $subscriptionPlan, $existingSubscription) {
            if ($existingSubscription && ! $this->canRenewSubscription($existingSubscription)) {
                abort(422, 'You can renew this subscription when it is close to expiry or after it expires.');
            }

            $startsAt = $existingSubscription?->expires_at && $existingSubscription->expires_at->isFuture()
                ? $existingSubscription->expires_at->copy()
                : now();

            return Subscription::updateOrCreate(
                [
                    'subscriber_id' => $request->user()->id,
                    'creator_id' => $subscriptionPlan->creator_id,
                ],
                [
                    'subscription_plan_id' => $subscriptionPlan->id,
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'expires_at' => $this->calculateExpiryAt($subscriptionPlan, $startsAt),
                    'blocked_at' => null,
                    'blocked_by' => null,
                    'blocked_reason' => null,
                ]
            );
        });

        $subscription->load([
            'subscriber:id,name,username,avatar',
            'creator:id,name,username,avatar',
            'plan',
        ]);

        return response()->json([
            'message' => 'Subscription created successfully.',
            'data' => (new SubscriptionResource($subscription))->toArray($request),
        ], 201);
    }

    // Block a subscriber when there is abuse or another policy violation.
    public function blockSubscriber(Request $request, Subscription $subscription)
    {
        abort_unless((string) $subscription->creator_id === (string) $request->user()->id, 403, 'You are not allowed to block this subscriber.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'requires_admin_review' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $subscription, $validated) {
            $subscription->update([
                'status' => 'blocked',
                'blocked_at' => now(),
                'blocked_by' => $request->user()->id,
                'blocked_reason' => $validated['reason'],
            ]);

            $this->recordAction(
                creatorId: $request->user()->id,
                subscriberId: $subscription->subscriber_id,
                subscriptionPlanId: $subscription->subscription_plan_id,
                subscriptionId: $subscription->id,
                action: 'block_user',
                reason: $validated['reason'],
                requiresAdminReview: $validated['requires_admin_review'] ?? true,
                metadata: [
                    'status' => 'blocked',
                    'blocked_at' => now()->toISOString(),
                ]
            );
        });

        $subscription->load([
            'subscriber:id,name,username,avatar',
            'creator:id,name,username,avatar',
            'plan',
        ]);

        return response()->json([
            'message' => 'Subscriber blocked successfully.',
            'data' => (new SubscriptionResource($subscription))->toArray($request),
        ]);
    }

    // Disable a plan so no new subscribers can join, while existing subscribers keep access until expiry.
    public function disablePlan(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $this->ensureOwnership($request->user(), $subscriptionPlan);

        DB::transaction(function () use ($request, $subscriptionPlan) {
            $subscriptionPlan->update([
                'is_active' => false,
            ]);

            $this->recordAction(
                creatorId: $request->user()->id,
                subscriberId: null,
                subscriptionPlanId: $subscriptionPlan->id,
                subscriptionId: null,
                action: 'disable_plan',
                reason: 'Creator disabled the subscription plan.',
                requiresAdminReview: false,
                metadata: [
                    'is_active' => false,
                ]
            );
        });

        $this->forgetCreatorPlansCache($subscriptionPlan->creator_id);

        $subscriptionPlan->load('creator:id,name,username,avatar');

        return response()->json([
            'message' => 'Subscription plan disabled successfully.',
            'data' => (new SubscriptionPlanResource($subscriptionPlan))->toArray($request),
        ]);
    }

    // Guard the write endpoints so only the plan owner can modify it.
    private function ensureOwnership(User $user, SubscriptionPlan $subscriptionPlan): void
    {
        abort_unless((string) $subscriptionPlan->creator_id === (string) $user->id, 403, 'You are not allowed to modify this subscription plan.');
    }

    // Return true when an active subscription is close enough to expiry to allow renewal.
    private function canRenewSubscription(Subscription $subscription): bool
    {
        if (! $subscription->expires_at) {
            return true;
        }

        if ($subscription->expires_at->isPast()) {
            return true;
        }

        return $subscription->expires_at->lessThanOrEqualTo(now()->addDays(self::RENEWAL_WINDOW_DAYS));
    }

    // Compute the next paid period based on the plan interval.
    private function calculateExpiryAt(SubscriptionPlan $subscriptionPlan, Carbon $startsAt): Carbon
    {
        return match ($subscriptionPlan->billing_interval) {
            'monthly' => $startsAt->copy()->addMonth(),
            default => $startsAt->copy()->addMonth(),
        };
    }

    // Cache keys are scoped per creator so one creator's changes do not affect another's feed.
    private function creatorPrivatePlansCacheKey(int $creatorId): string
    {
        return "subscription-plans:creator:{$creatorId}:private";
    }

    // Public cache is for fan-facing reads and only stores active plans.
    private function creatorPublicPlansCacheKey(int $creatorId): string
    {
        return "subscription-plans:creator:{$creatorId}:public";
    }

    // Use the app's configured cache store so local dev can fall back to database/file.
    private function cacheStore()
    {
        return Cache::store(config('cache.default'));
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function getCreatorPrivatePlans(Request $request): array
    {
        $cache = $this->cacheStore();
        $key = $this->creatorPrivatePlansCacheKey($request->user()->id);

        if ($cache->has($key)) {
            return [$cache->get($key), true];
        }

        $plans = $request->user()
            ->subscriptionPlans()
            ->with('creator:id,name,username,avatar')
            ->orderByDesc('is_active')
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => (new SubscriptionPlanResource($plan))->toArray($request))
            ->values()
            ->all();

        $cache->put($key, $plans, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [$plans, false];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function getCreatorPublicPlans(User $creator, Request $request): array
    {
        $cache = $this->cacheStore();
        $key = $this->creatorPublicPlansCacheKey($creator->id);

        if ($cache->has($key)) {
            return [$cache->get($key), true];
        }

        $plans = $creator->subscriptionPlans()
            ->active()
            ->with('creator:id,name,username,avatar')
            ->orderByDesc('is_active')
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => (new SubscriptionPlanResource($plan))->toArray($request))
            ->values()
            ->all();

        $cache->put($key, $plans, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [$plans, false];
    }

    // Invalidate both cache variants after writes so the creator and fan views stay in sync.
    private function forgetCreatorPlansCache(int $creatorId): void
    {
        $cache = $this->cacheStore();

        $cache->forget($this->creatorPrivatePlansCacheKey($creatorId));
        $cache->forget($this->creatorPublicPlansCacheKey($creatorId));
    }

    // Persist an audit record whenever a creator changes subscription access.
    private function recordAction(
        int $creatorId,
        ?int $subscriberId,
        ?int $subscriptionPlanId,
        ?int $subscriptionId,
        string $action,
        ?string $reason,
        bool $requiresAdminReview,
        array $metadata = []
    ): void {
        SubscriptionAction::create([
            'creator_id' => $creatorId,
            'subscriber_id' => $subscriberId,
            'subscription_plan_id' => $subscriptionPlanId,
            'subscription_id' => $subscriptionId,
            'action' => $action,
            'reason' => $reason,
            'requires_admin_review' => $requiresAdminReview,
            'review_status' => $requiresAdminReview ? 'pending' : 'not_required',
            'metadata' => $metadata,
        ]);
    }
}
