<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoPlaylist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function videos()
    {
        return $this->belongsToMany(Video::class, 'video_playlist_video')
            ->withTimestamps();
    }

    public function getBackgroundAttribute(): ?string
    {
        $video = $this->resolveBackgroundVideo();

        if (! $video) {
            return null;
        }

        return $video->poster_url ?: $video->thumbnail_url ?: data_get($video->metadata, 'background');
    }

    private function resolveBackgroundVideo(): ?Video
    {
        if ($this->relationLoaded('videos')) {
            return $this->videos
                ->sortBy(function (Video $video): int {
                    $pivotCreatedAt = data_get($video, 'pivot.created_at');

                    return $pivotCreatedAt?->timestamp
                        ?? (int) $video->id;
                })
                ->first();
        }

        return $this->videos()
            ->orderBy('video_playlist_video.created_at')
            ->orderBy('video_playlist_video.id')
            ->first();
    }
}
