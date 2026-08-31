<?php

namespace App\Services;

use App\Enums\LiveRecordingStatus;
use App\Enums\LiveStatus;
use App\Models\LiveRecording;
use App\Models\LiveSession;

class LiveReconciliationService
{
    public function reconcileStaleLive(LiveSession $live): bool
    {
        if ($live->status !== LiveStatus::RECONNECTING) {
            return false;
        }

        $grace = (int) config('live.reconnect_grace_seconds', 45);

        if (! $live->updated_at || $live->updated_at->addSeconds($grace)->isFuture()) {
            return false;
        }

        $live->forceFill([
            'status' => LiveStatus::ENDED,
            'ended_at' => now(),
            'termination_reason' => 'system_timeout',
        ])->saveQuietly();

        LiveRecording::query()
            ->where('live_session_id', $live->id)
            ->whereIn('status', [LiveRecordingStatus::REQUESTED, LiveRecordingStatus::RECORDING, LiveRecordingStatus::PROCESSING])
            ->update(['status' => LiveRecordingStatus::FAILED]);

        return true;
    }
}

