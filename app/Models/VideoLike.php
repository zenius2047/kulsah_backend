<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoLike extends Model
{
    protected $fillable = [
        'video_id',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::created(static function (self $like): void {
            Video::query()->whereKey($like->video_id)->increment('likes_count');
        });

        static::deleted(static function (self $like): void {
            Video::query()
                ->whereKey($like->video_id)
                ->where('likes_count', '>', 0)
                ->decrement('likes_count');
        });
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
