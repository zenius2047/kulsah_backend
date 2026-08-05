<?php

namespace App\Models;

use App\Services\FeedService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'video_id',
        'user_id',
        'parent_id',
        'body',
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

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function likes()
    {
        return $this->hasMany(VideoCommentLike::class, 'video_comment_id');
    }
}
