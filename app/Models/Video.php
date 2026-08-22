<?php

namespace App\Models;

use App\Enums\VideoProcessingStatus;
use App\Enums\VideoPurpose;
use App\Enums\VideoUploadStatus;
use App\Services\CloudinaryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'duet_source_video_id',
        'media_type',
        'purpose',
        'title',
        'caption',
        'visibility',
        'allow_duet',
        'content_type',
        'content_types',
        'original_filename',
        'mime_type',
        'file_size',
        'source_disk',
        'source_bucket',
        'source_url',
        'source_key',
        'upload_status',
        'processing_status',
        'cdn_url',
        'rendered_url',
        'streaming_url',
        'playback_type',
        'hls_url',
        'dash_url',
        'fallback_mp4_url',
        'poster_url',
        'cloudinary_public_id',
        'cloudinary_asset_id',
        'cloudinary_render_id',
        'thumbnail_url',
        'duration',
        'duration_ms',
        'width',
        'height',
        'aspect_ratio',
        'fps',
        'status',
        'render_status',
        'processing_error',
        'uploaded_at',
        'processing_started_at',
        'processed_at',
        'failed_at',
        'progress_percentage',
        'render_completed_at',
        'views_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'content_types' => 'array',
            'duration' => 'integer',
            'duration_ms' => 'integer',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'fps' => 'decimal:3',
            'purpose' => VideoPurpose::class,
            'upload_status' => VideoUploadStatus::class,
            'processing_status' => VideoProcessingStatus::class,
            'allow_duet' => 'boolean',
            'progress_percentage' => 'integer',
            'views_count' => 'integer',
            'render_completed_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getIsPremiumAttribute(): bool
    {
        return $this->visibility === 'premium';
    }

    public function getPlaybackUrlAttribute(): ?string
    {
        if (is_string($this->hls_url) && $this->hls_url !== '') {
            return $this->hls_url;
        }

        if (is_string($this->streaming_url) && $this->streaming_url !== '') {
            return $this->streaming_url;
        }

        if (is_string($this->cdn_url) && $this->cdn_url !== '') {
            return $this->cdn_url;
        }

        if (is_string($this->rendered_url) && $this->rendered_url !== '') {
            return $this->rendered_url;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $publicId = $this->cloudinary_public_id ?: data_get($metadata, 'cloudinary_public_id');

        if (is_string($publicId) && $publicId !== '') {
            return app(CloudinaryService::class)->generateStreamingUrlFromPublicId($publicId);
        }

        return null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function duetSourceVideo()
    {
        return $this->belongsTo(self::class, 'duet_source_video_id');
    }

    public function duetVideos()
    {
        return $this->hasMany(self::class, 'duet_source_video_id');
    }

    public function playlists()
    {
        return $this->belongsToMany(VideoPlaylist::class, 'video_playlist_video')
            ->withTimestamps();
    }

    public function likes()
    {
        return $this->hasMany(VideoLike::class);
    }

    public function comments()
    {
        return $this->hasMany(VideoComment::class);
    }

    public function bookmarks()
    {
        return $this->hasMany(VideoBookmark::class);
    }

    public function challengeEntries()
    {
        return $this->hasMany(ChallengeEntry::class);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready')->where('processing_status', VideoProcessingStatus::Ready);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}