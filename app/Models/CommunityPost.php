<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'content',
        'audience',
        'status',
        'views_count',
        'media_ids',
        'poll',
    ];

    protected function casts(): array
    {
        return [
            'views_count' => 'integer',
            'media_ids' => 'array',
            'poll' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(CommunityPostComment::class, 'community_post_id');
    }

    public function media()
    {
        return $this->hasMany(CommunityPostMedia::class, 'community_post_id')->orderBy('sort_order');
    }

    public function pollVotes()
    {
        return $this->hasMany(CommunityPostPollVote::class, 'community_post_id');
    }

    public function likes()
    {
        return $this->hasMany(CommunityPostLike::class, 'community_post_id');
    }

    public function shares()
    {
        return $this->hasMany(CommunityPostShare::class, 'community_post_id');
    }

    public function gifts()
    {
        return $this->hasMany(CommunityPostGift::class, 'community_post_id');
    }
}
