<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoCommentLike extends Model
{
    protected $fillable = [
        'video_comment_id',
        'user_id',
    ];

    public function comment()
    {
        return $this->belongsTo(VideoComment::class, 'video_comment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
