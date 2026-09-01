<?php

return [
    'views' => [
        'minimum_visible_percentage' => (float) env('COMMUNITY_MIN_VISIBLE_PERCENTAGE', 50),
        'minimum_visible_seconds' => (float) env('COMMUNITY_MIN_VISIBLE_SECONDS', 1.5),
        'minimum_video_watch_seconds' => (float) env('COMMUNITY_MIN_VIDEO_WATCH_SECONDS', 1.0),
        'maximum_watch_seconds' => (float) env('COMMUNITY_MAX_WATCH_SECONDS', 14400),
        'public_count_cooldown_seconds' => (int) env('COMMUNITY_VIEW_COOLDOWN_SECONDS', 30),
    ],

    'feed' => [
        'candidate_multiplier' => (int) env('COMMUNITY_FEED_CANDIDATE_MULTIPLIER', 8),
        'maximum_candidates' => (int) env('COMMUNITY_FEED_MAXIMUM_CANDIDATES', 500),
        'recent_view_penalty' => (float) env('COMMUNITY_RECENT_VIEW_PENALTY', 30),
        'repeat_view_penalty' => (float) env('COMMUNITY_REPEAT_VIEW_PENALTY', 4),
        'new_activity_boost' => (float) env('COMMUNITY_NEW_ACTIVITY_BOOST', 14),
        'engaged_post_boost' => (float) env('COMMUNITY_ENGAGED_POST_BOOST', 7),
        'followed_creator_boost' => (float) env('COMMUNITY_FOLLOWED_CREATOR_BOOST', 12),
        'partial_watch_boost' => (float) env('COMMUNITY_PARTIAL_WATCH_BOOST', 4),
        'trending_boost' => (float) env('COMMUNITY_TRENDING_BOOST', 15),
        'resurface_after_hours' => (int) env('COMMUNITY_RESURFACE_AFTER_HOURS', 24),
        'strong_resurface_after_hours' => (int) env('COMMUNITY_STRONG_RESURFACE_AFTER_HOURS', 72),
        'session_ttl_seconds' => (int) env('COMMUNITY_FEED_SESSION_TTL_SECONDS', 1800),
        'session_repeat_window' => (int) env('COMMUNITY_FEED_SESSION_REPEAT_WINDOW', 100),
        'diversity_window' => (int) env('COMMUNITY_FEED_DIVERSITY_WINDOW', 10),
        'max_same_creator_per_window' => (int) env('COMMUNITY_MAX_SAME_CREATOR_PER_WINDOW', 2),
        'max_same_type_per_window' => (int) env('COMMUNITY_MAX_SAME_TYPE_PER_WINDOW', 3),
    ],
];
