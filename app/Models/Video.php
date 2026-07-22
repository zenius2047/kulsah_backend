<?php

namespace App\Models;

use App\Services\CloudinaryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'caption',
        'visibility',
        'content_type',
        'content_types',
        'source_url',
        'source_key',
        'cdn_url',
        'rendered_url',
        'streaming_url',
        'poster_url',
        'cloudinary_public_id',
        'cloudinary_asset_id',
        'cloudinary_render_id',
        'thumbnail_url',
        'duration',
        'status',
        'render_status',
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
            'progress_percentage' => 'integer',
            'views_count' => 'integer',
            'render_completed_at' => 'datetime',
        ];
    }

    public function getIsPremiumAttribute(): bool
    {
        return $this->visibility === 'premium';
    }

    public function getPlaybackUrlAttribute(): ?string
    {
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

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
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
