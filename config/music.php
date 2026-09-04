<?php

return [
    'default_provider' => env('MUSIC_DEFAULT_PROVIDER', 'audius'),
    'max_limit' => (int) env('MUSIC_MAX_LIMIT', 50),
    'default_limit' => (int) env('MUSIC_DEFAULT_LIMIT', 20),
    'max_search_length' => (int) env('MUSIC_MAX_SEARCH_LENGTH', 150),
    'cache_ttl_seconds' => [
        'trending' => (int) env('MUSIC_TRENDING_CACHE_TTL', 300),
        'search' => (int) env('MUSIC_SEARCH_CACHE_TTL', 90),
        'track' => (int) env('MUSIC_TRACK_CACHE_TTL', 600),
    ],
    'stale_ttl_seconds' => [
        'trending' => (int) env('MUSIC_TRENDING_STALE_TTL', 1800),
        'search' => (int) env('MUSIC_SEARCH_STALE_TTL', 900),
        'track' => (int) env('MUSIC_TRACK_STALE_TTL', 3600),
    ],
    'genres' => [
        'afrobeats' => ['Afrobeats', 'Afrobeat'],
        'amapiano' => ['Amapiano'],
        'hip_hop_rap' => ['Hip-Hop/Rap', 'Hip Hop', 'Rap', 'Hip-Hop'],
        'rnb_soul' => ['R&B/Soul', 'R&B', 'Soul'],
        'dancehall' => ['Dancehall'],
        'electronic' => ['Electronic', 'EDM'],
        'pop' => ['Pop'],
        'gospel' => ['Gospel'],
        'latin' => ['Latin'],
        'jazz' => ['Jazz'],
        'alternative' => ['Alternative'],
        'rock' => ['Rock'],
        'new_music' => ['New Music'],
    ],
];
