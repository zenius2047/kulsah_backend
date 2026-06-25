<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAction extends Model
{
    protected $fillable = [
        'creator_id',
        'subscriber_id',
        'subscription_plan_id',
        'subscription_id',
        'action',
        'reason',
        'requires_admin_review',
        'review_status',
        'metadata',
    ];

    protected $casts = [
        'requires_admin_review' => 'boolean',
        'metadata' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function subscriber()
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
