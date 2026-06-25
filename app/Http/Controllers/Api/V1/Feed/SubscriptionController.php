<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $subscriptions = Subscription::query()
            ->with([
                'creator:id,name,username,avatar',
                'plan',
            ])
            ->where('subscriber_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Subscription $subscription) => $this->formatSubscription($subscription));

        return response()->json([
            'data' => $subscriptions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'creator_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'subscription_plan_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('subscription_plans', 'id'),
            ],
        ]);

        $subscriberId = $request->user()->id;

        abort_if((string) $validated['creator_id'] === (string) $subscriberId, 422, 'You cannot subscribe to yourself.');

        $plan = null;

        if (array_key_exists('subscription_plan_id', $validated) && $validated['subscription_plan_id']) {
            $plan = SubscriptionPlan::query()
                ->whereKey($validated['subscription_plan_id'])
                ->where('creator_id', $validated['creator_id'])
                ->where('is_active', true)
                ->firstOrFail();
        }

        $subscription = DB::transaction(function () use ($subscriberId, $validated, $plan) {
            return Subscription::query()->updateOrCreate(
                [
                    'subscriber_id' => $subscriberId,
                    'creator_id' => $validated['creator_id'],
                ],
                [
                    'subscription_plan_id' => $plan?->id,
                ]
            );
        });

        $subscription->load([
            'creator:id,name,username,avatar',
            'plan',
        ]);

        return response()->json([
            'message' => 'Subscription created successfully.',
            'data' => $this->formatSubscription($subscription),
        ], 201);
    }

    public function destroy(Request $request, Subscription $subscription)
    {
        abort_unless(
            $subscription->subscriber_id === $request->user()->id || $subscription->creator_id === $request->user()->id,
            403
        );

        $subscription->delete();

        return response()->json([
            'message' => 'Subscription removed successfully.',
        ]);
    }

    public function plans(Request $request)
    {
        $plans = SubscriptionPlan::query()
            ->with('creator:id,name,username,avatar')
            ->where('creator_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (SubscriptionPlan $plan) => $this->formatPlan($plan));

        return response()->json([
            'data' => $plans,
        ]);
    }

    public function storePlan(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'billing_interval' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan = $request->user()->subscriptionPlans()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'] ?? 0,
            'currency' => $validated['currency'] ?? 'GHS',
            'billing_interval' => $validated['billing_interval'] ?? 'monthly',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $plan->load('creator:id,name,username,avatar');

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'data' => $this->formatPlan($plan),
        ], 201);
    }

    public function updatePlan(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        abort_unless($subscriptionPlan->creator_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'billing_interval' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $subscriptionPlan->fill($validated);
        $subscriptionPlan->save();
        $subscriptionPlan->load('creator:id,name,username,avatar');

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'data' => $this->formatPlan($subscriptionPlan),
        ]);
    }

    public function destroyPlan(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        abort_unless($subscriptionPlan->creator_id === $request->user()->id, 403);

        $subscriptionPlan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }

    private function formatSubscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'subscriber_id' => $subscription->subscriber_id,
            'creator_id' => $subscription->creator_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'creator' => $subscription->creator ? [
                'id' => $subscription->creator->id,
                'name' => $subscription->creator->name,
                'username' => $subscription->creator->username,
                'avatar' => $subscription->creator->avatar,
            ] : null,
            'plan' => $subscription->plan ? $this->formatPlan($subscription->plan) : null,
            'created_at' => $subscription->created_at,
            'updated_at' => $subscription->updated_at,
        ];
    }

    private function formatPlan(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'creator_id' => $plan->creator_id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'is_active' => $plan->is_active,
            'creator' => $plan->creator ? [
                'id' => $plan->creator->id,
                'name' => $plan->creator->name,
                'username' => $plan->creator->username,
                'avatar' => $plan->creator->avatar,
            ] : null,
            'created_at' => $plan->created_at,
            'updated_at' => $plan->updated_at,
        ];
    }
}
