<?php

return [
    'storage_disk' => env('VIDEO_STORAGE_DISK', 's3'),
    'upload_directory' => env('VIDEO_UPLOAD_DIRECTORY', 'videos/originals'),
    'allowed_mimetypes' => explode(',', env('VIDEO_ALLOWED_MIMETYPES', 'video/mp4,video/quicktime,video/webm,video/x-matroska')),
    'max_upload_kb' => (int) env('VIDEO_MAX_UPLOAD_KB', 102400),
    'max_duration_seconds' => (int) env('VIDEO_MAX_DURATION_SECONDS', 120),
    'transcode_enabled' => env('VIDEO_TRANSCODE_ENABLED', true),
    'transcode_preset' => env('VIDEO_TRANSCODE_PRESET', 'veryfast'),
    'transcode_crf' => (int) env('VIDEO_TRANSCODE_CRF', 28),
    'transcode_max_height' => (int) env('VIDEO_TRANSCODE_MAX_HEIGHT', 2160),
    'transcode_audio_bitrate' => env('VIDEO_TRANSCODE_AUDIO_BITRATE', '128k'),
    'cloudinary_stream_manifest_extension' => env('CLOUDINARY_STREAM_MANIFEST_EXTENSION', 'm3u8'),
    'cloudinary_stream_max_resolution' => env('CLOUDINARY_STREAM_MAX_RESOLUTION', '2160p'),
    'cache_ttl_seconds' => (int) env('VIDEO_CACHE_TTL_SECONDS', 300),
    'direct_upload_ttl_minutes' => (int) env('VIDEO_DIRECT_UPLOAD_TTL_MINUTES', 60),
    'feed_cache_ttl_seconds' => (int) env('FEED_CACHE_TTL_SECONDS', 600),
    'processing_queue' => env('VIDEO_PROCESSING_QUEUE', 'videos'),
    'cloudinary_upload_timeout_seconds' => (int) env('CLOUDINARY_UPLOAD_TIMEOUT_SECONDS', 120),
];
