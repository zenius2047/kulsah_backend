<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveAnalytics extends Model
{
    protected $fillable = [
        'live_session_id',
        'unique_viewers',
        'peak_viewers',
        'average_viewers',
        'watch_seconds',
        'comments_count',
        'likes_count',
        'gifts_count',
        'gross_gift_value_kc',
        'creator_earnings_kc',
        'platform_revenue_kc',
        'new_fans_count',
        'new_subscribers_count',
        'cohost_requests_count',
        'successful_cohosts_count',
        'reports_count',
        'mutes_count',
        'removals_count',
        'disconnects_count',
        'reconnects_count',
        'metadata',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'unique_viewers' => 'integer',
            'peak_viewers' => 'integer',
            'average_viewers' => 'integer',
            'watch_seconds' => 'integer',
            'comments_count' => 'integer',
            'likes_count' => 'integer',
            'gifts_count' => 'integer',
            'gross_gift_value_kc' => 'integer',
            'creator_earnings_kc' => 'integer',
            'platform_revenue_kc' => 'integer',
            'new_fans_count' => 'integer',
            'new_subscribers_count' => 'integer',
            'cohost_requests_count' => 'integer',
            'successful_cohosts_count' => 'integer',
            'reports_count' => 'integer',
            'mutes_count' => 'integer',
            'removals_count' => 'integer',
            'disconnects_count' => 'integer',
            'reconnects_count' => 'integer',
            'metadata' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }
}
