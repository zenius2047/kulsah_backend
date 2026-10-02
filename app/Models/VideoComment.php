<?php

namespace App\Models;

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
        'sticker_id',
    ];

    protected static function booted(): void
    {
        static::created(static function (self $comment): void {
            Video::query()->whereKey($comment->video_id)->increment('comments_count');
        });

        static::deleted(static function (self $comment): void {
            Video::query()
                ->whereKey($comment->video_id)
                ->where('comments_count', '>', 0)
                ->decrement('comments_count');
        });

        static::restored(static function (self $comment): void {
            Video::query()->whereKey($comment->video_id)->increment('comments_count');
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
