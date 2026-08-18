<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPostView extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'community_post_id',
        'first_viewed_at',
        'last_viewed_at',
        'view_count',
        'watch_duration_seconds',
        'last_watch_duration_seconds',
        'completion_percentage',
        'max_completion_percentage',
        'completed_count',
        'reached_25_percent',
        'reached_50_percent',
        'reached_75_percent',
        'reached_90_percent',
        'engaged',
        'last_engaged_at',
        'last_counted_at',
    ];

    protected function casts(): array
    {
        return [
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'last_engaged_at' => 'datetime',
            'last_counted_at' => 'datetime',
            'view_count' => 'integer',
            'watch_duration_seconds' => 'decimal:3',
            'last_watch_duration_seconds' => 'decimal:3',
            'completion_percentage' => 'decimal:2',
            'max_completion_percentage' => 'decimal:2',
            'completed_count' => 'integer',
            'reached_25_percent' => 'boolean',
            'reached_50_percent' => 'boolean',
            'reached_75_percent' => 'boolean',
            'reached_90_percent' => 'boolean',
            'engaged' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }
}
