<?php

namespace App\Models;

use App\Enums\LiveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'creator_id',
        'title',
        'description',
        'category',
        'cover_url',
        'visibility',
        'scheduled_at',
        'started_at',
        'ended_at',
        'last_heartbeat_at',
        'heartbeat_payload',
        'provider_metadata',
        'status',
        'provider',
        'provider_channel',
        'chat_enabled',
        'gifts_enabled',
        'recording_enabled',
        'current_viewers',
        'unique_viewers',
        'peak_viewers',
        'average_viewers',
        'watch_seconds_total',
        'likes_count',
        'comments_count',
        'gifts_count',
        'gift_value_kc',
        'earnings_kc',
        'termination_reason',
        'recording_state',
        'replay_state',
    ];

    protected function casts(): array
    {
        return [
            'status' => LiveStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'heartbeat_payload' => 'array',
            'provider_metadata' => 'array',
            'chat_enabled' => 'boolean',
            'gifts_enabled' => 'boolean',
            'recording_enabled' => 'boolean',
            'current_viewers' => 'integer',
            'unique_viewers' => 'integer',
            'peak_viewers' => 'integer',
            'average_viewers' => 'integer',
            'watch_seconds_total' => 'integer',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'gifts_count' => 'integer',
            'gift_value_kc' => 'integer',
            'earnings_kc' => 'integer',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function viewerSessions()
    {
        return $this->hasMany(LiveViewerSession::class);
    }

    public function comments()
    {
        return $this->hasMany(LiveComment::class);
    }

    public function cohosts()
    {
        return $this->hasMany(LiveCohost::class);
    }

    public function cohostRequests()
    {
        return $this->hasMany(LiveCohostRequest::class);
    }

    public function moderators()
    {
        return $this->hasMany(LiveModerator::class);
    }

    public function moderationActions()
    {
        return $this->hasMany(LiveModerationAction::class);
    }

    public function recordings()
    {
        return $this->hasMany(LiveRecording::class);
    }

    public function analytics()
    {
        return $this->hasOne(LiveAnalytics::class);
    }

    public function battlesAsCreator()
    {
        return $this->hasMany(LiveBattle::class, 'creator_live_session_id');
    }

    public function battlesAsOpponent()
    {
        return $this->hasMany(LiveBattle::class, 'opponent_live_session_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [LiveStatus::STARTING, LiveStatus::LIVE, LiveStatus::RECONNECTING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [LiveStatus::STARTING, LiveStatus::LIVE, LiveStatus::RECONNECTING, LiveStatus::ENDING], true);
    }

    public function canTransitionTo(LiveStatus $next): bool
    {
        return ($this->status?->canTransitionTo($next)) ?? false;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}

