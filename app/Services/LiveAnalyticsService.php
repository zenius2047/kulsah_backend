<?php

namespace App\Services;

use App\Models\LiveAnalytics;
use App\Models\LiveSession;

class LiveAnalyticsService
{
    public function upsertFromLive(LiveSession $live, array $metadata = []): LiveAnalytics
    {
        return LiveAnalytics::query()->updateOrCreate(
            ['live_session_id' => $live->id],
            [
                'unique_viewers' => (int) $live->unique_viewers,
                'peak_viewers' => (int) $live->peak_viewers,
                'average_viewers' => (int) ($metadata['average_viewers'] ?? $live->current_viewers),
                'watch_seconds' => (int) ($metadata['watch_seconds'] ?? 0),
                'comments_count' => (int) $live->comments_count,
                'likes_count' => (int) $live->likes_count,
                'gifts_count' => (int) $live->gifts_count,
                'gross_gift_value_kc' => (int) $live->gift_value_kc,
                'creator_earnings_kc' => (int) ($metadata['creator_earnings_kc'] ?? 0),
                'platform_revenue_kc' => (int) ($metadata['platform_revenue_kc'] ?? 0),
                'new_fans_count' => (int) ($metadata['new_fans_count'] ?? 0),
                'new_subscribers_count' => (int) ($metadata['new_subscribers_count'] ?? 0),
                'cohost_requests_count' => (int) ($metadata['cohost_requests_count'] ?? 0),
                'successful_cohosts_count' => (int) ($metadata['successful_cohosts_count'] ?? 0),
                'reports_count' => (int) ($metadata['reports_count'] ?? 0),
                'mutes_count' => (int) ($metadata['mutes_count'] ?? 0),
                'removals_count' => (int) ($metadata['removals_count'] ?? 0),
                'disconnects_count' => (int) ($metadata['disconnects_count'] ?? 0),
                'reconnects_count' => (int) ($metadata['reconnects_count'] ?? 0),
                'metadata' => $metadata,
                'calculated_at' => now(),
            ]
        );
    }
}

