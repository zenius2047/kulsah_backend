<?php

namespace App\Models;

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
        'cloudinary_public_id',
        'thumbnail_url',
        'duration',
        'status',
        'progress_percentage',
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
        ];
    }

    public function getIsPremiumAttribute(): bool
    {
        return $this->visibility === 'premium';
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
