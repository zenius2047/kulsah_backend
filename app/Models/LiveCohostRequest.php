<?php

namespace App\Models;

use App\Enums\LiveCohostRequestStatus;
use Illuminate\Database\Eloquent\Model;

class LiveCohostRequest extends Model
{
    protected $fillable = [
        'live_session_id',
        'requester_id',
        'invitee_id',
        'requested_by_id',
        'status',
        'message',
        'expires_at',
        'responded_at',
        'accepted_at',
        'declined_at',
        'cancelled_at',
        'removed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LiveCohostRequestStatus::class,
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'removed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function invitee()
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
