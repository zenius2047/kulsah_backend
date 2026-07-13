<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscriber_id' => $this->subscriber_id,
            'creator_id' => $this->creator_id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'blocked_at' => $this->blocked_at,
            'blocked_by' => $this->blocked_by,
            'blocked_reason' => $this->blocked_reason,
            'subscriber' => $this->whenLoaded('subscriber', function () {
                return [
                    'id' => $this->subscriber->id,
                    'name' => $this->subscriber->name,
                    'username' => $this->subscriber->username,
                    'avatar' => $this->subscriber->avatar,
                    'banner' => $this->subscriber->banner,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'username' => $this->creator->username,
                    'avatar' => $this->creator->avatar,
                    'banner' => $this->creator->banner,
                ];
            }),
            'plan' => $this->whenLoaded('plan', function () {
                return [
                    'id' => $this->plan->id,
                    'name' => $this->plan->name,
                    'description' => $this->plan->description,
                    'price' => $this->plan->price,
                    'currency' => $this->plan->currency,
                    'billing_interval' => $this->plan->billing_interval,
                    'is_active' => $this->plan->is_active,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
