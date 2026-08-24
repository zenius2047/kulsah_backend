<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FeedViewerState extends Model
{
    use HasFactory;

    protected $fillable = [
        'viewer_key',
        'user_id',
        'device_key',
        'seen_video_ids',
        'interest_terms',
        'creator_affinity',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'seen_video_ids' => 'array',
            'interest_terms' => 'array',
            'creator_affinity' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

