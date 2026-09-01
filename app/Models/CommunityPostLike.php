<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPostLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_post_id',
        'user_id',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
