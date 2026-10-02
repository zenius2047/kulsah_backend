<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoBookmark extends Model
{
    protected $fillable = [
        'video_id',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::created(static function (self $bookmark): void {
            Video::query()->whereKey($bookmark->video_id)->increment('bookmarks_count');
        });

        static::deleted(static function (self $bookmark): void {
            Video::query()
                ->whereKey($bookmark->video_id)
                ->where('bookmarks_count', '>', 0)
                ->decrement('bookmarks_count');
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
