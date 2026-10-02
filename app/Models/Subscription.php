<?php

namespace App\Models;

use App\Services\SignalMessagingService;
use App\Services\VideoCacheService;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'subscriber_id',
        'creator_id',
        'subscription_plan_id',
        'status',
        'starts_at',
        'expires_at',
        'blocked_at',
        'blocked_by',
        'blocked_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'blocked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(static function (self $subscription): void {
            app(VideoCacheService::class)->invalidateViewer((int) $subscription->subscriber_id);

            if ($subscription->isActiveSubscription()) {
                app(SignalMessagingService::class)->ensureSubscriptionPromotesFan(
                    User::find($subscription->subscriber_id),
                    User::find($subscription->creator_id)
                );
            }
        });

        static::deleted(static function (self $subscription): void {
            app(VideoCacheService::class)->invalidateViewer((int) $subscription->subscriber_id);
        });
    }

    public function subscriber()
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function blocker()
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isActiveSubscription(): bool
    {
        return $this->status === 'active' && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
