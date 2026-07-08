<?php

namespace App\Models;

use App\Services\FeedService;
use Illuminate\Database\Eloquent\Model;

class VideoCommentLike extends Model
{
    protected $fillable = [
        'video_comment_id',
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

    public function comment()
    {
        return $this->belongsTo(VideoComment::class, 'video_comment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
