<?php

namespace App\Models;

use App\Services\FeedService;
use Illuminate\Database\Eloquent\Model;

class UserFollow extends Model
{
    protected $fillable = [
        'follower_id',
        'followed_id',
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

    public function follower()
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function followed()
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}
