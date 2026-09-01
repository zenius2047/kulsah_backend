<?php

return [
    'enabled' => (bool) env('LIVE_ENABLED', true),
    'provider' => env('LIVE_PROVIDER', 'agora'),
    'reconnect_grace_seconds' => (int) env('LIVE_RECONNECT_GRACE_SECONDS', 45),
    'presence_ttl_seconds' => (int) env('LIVE_PRESENCE_TTL_SECONDS', 90),
    'like_flush_seconds' => (int) env('LIVE_LIKE_FLUSH_SECONDS', 5),
    'heartbeat_ttl_seconds' => (int) env('LIVE_HEARTBEAT_TTL_SECONDS', 15),
    'cohost_limit' => (int) env('LIVE_COHOST_LIMIT', 4),
    'moderator_limit' => (int) env('LIVE_MODERATOR_LIMIT', 10),
    'viewer_session_ttl_seconds' => (int) env('LIVE_VIEWER_SESSION_TTL_SECONDS', 120),
    'watch_reconciliation_grace_seconds' => (int) env('LIVE_WATCH_RECONCILIATION_GRACE_SECONDS', 300),
    'discovery_cache_seconds' => (int) env('LIVE_DISCOVERY_CACHE_SECONDS', 30),
    'features' => [
        'gifts' => (bool) env('LIVE_GIFTS_ENABLED', true),
        'cohost' => (bool) env('LIVE_COHOST_ENABLED', true),
        'battle' => (bool) env('LIVE_BATTLE_ENABLED', true),
        'recording' => (bool) env('LIVE_RECORDING_ENABLED', false),
        'replay' => (bool) env('LIVE_REPLAY_ENABLED', false),
        'transcoding' => (bool) env('LIVE_TRANSCODING_ENABLED', false),
        'media_push' => (bool) env('LIVE_MEDIA_PUSH_ENABLED', false),
    ],
];

