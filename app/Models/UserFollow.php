<?php

namespace App\Models;

use App\Services\VideoCacheService;
use Illuminate\Database\Eloquent\Model;

class UserFollow extends Model
{
    protected $fillable = [
        'follower_id',
        'followed_id',
    ];

    protected static function booted(): void
    {
        static::saved(static function (self $follow): void {
            app(VideoCacheService::class)->invalidateViewer((int) $follow->follower_id);
        });

        static::deleted(static function (self $follow): void {
            app(VideoCacheService::class)->invalidateViewer((int) $follow->follower_id);
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
