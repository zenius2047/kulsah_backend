<?php

namespace App\Models;

use App\Services\FeedService;
use Illuminate\Database\Eloquent\Model;

class VideoBookmark extends Model
{
    protected $fillable = [
        'video_id',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::saved(static function (): void {
            app(FeedService::class)->invalidateFeedCaches();
        });

        static::deleted(static function (): void {
            app(FeedService::class)->invalidateFeedCaches();
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
