<?php

namespace App\Models;

use App\Services\FeedService;
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
        static::saved(static function (): void {
            app(FeedService::class)->invalidateFeedCaches();
        });

        static::deleted(static function (): void {
            app(FeedService::class)->invalidateFeedCaches();
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
}
