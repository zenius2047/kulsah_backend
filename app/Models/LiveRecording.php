<?php

namespace App\Models;

use App\Enums\LiveRecordingStatus;
use Illuminate\Database\Eloquent\Model;

class LiveRecording extends Model
{
    protected $fillable = [
        'live_session_id',
        'provider',
        'provider_resource_id',
        'provider_sid',
        'status',
        'storage_disk',
        'storage_path',
        'replay_state',
        'started_at',
        'stopped_at',
        'processed_at',
        'published_at',
        'deleted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LiveRecordingStatus::class,
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'processed_at' => 'datetime',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function live()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }
}
