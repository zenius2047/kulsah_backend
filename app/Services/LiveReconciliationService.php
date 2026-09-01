<?php

namespace App\Services;

use App\Enums\LiveRecordingStatus;
use App\Enums\LiveStatus;
use App\Events\LiveUpdated;
use App\Models\LiveRecording;
use App\Models\LiveSession;

class LiveReconciliationService
{
    public function reconcileStaleLive(LiveSession $live): bool
    {
        $now = now();
        $heartbeatTtl = (int) config('live.heartbeat_ttl_seconds', 15);
        $grace = (int) config('live.reconnect_grace_seconds', 45);
        $lastSignal = $live->status === LiveStatus::RECONNECTING
            ? $live->updated_at
            : ($live->last_heartbeat_at ?: $live->updated_at);

        if (! $lastSignal) {
            return false;
        }

        if ($live->status === LiveStatus::LIVE) {
            if ($lastSignal->addSeconds($heartbeatTtl)->isFuture()) {
                return false;
            }

            $live->forceFill(['status' => LiveStatus::RECONNECTING])->saveQuietly();
            LiveUpdated::dispatch($live->fresh('creator'), 'status');

            return true;
        }

        $timeout = $live->status === LiveStatus::STARTING
            ? $heartbeatTtl + $grace
            : $grace;

        if ($live->status !== LiveStatus::RECONNECTING && $live->status !== LiveStatus::STARTING) {
            return false;
        }

        if ($lastSignal->addSeconds($timeout)->isFuture()) {
            return false;
        }

        $live->forceFill([
            'status' => LiveStatus::ENDED,
            'ended_at' => $now,
            'termination_reason' => 'system_timeout',
        ])->saveQuietly();

        LiveRecording::query()
            ->where('live_session_id', $live->id)
            ->whereIn('status', [LiveRecordingStatus::REQUESTED, LiveRecordingStatus::RECORDING, LiveRecordingStatus::PROCESSING])
            ->update(['status' => LiveRecordingStatus::FAILED]);

        $live->viewerSessions()->whereNull('left_at')->each(function ($session): void {
            app(LivePresenceService::class)->leave($session);
        });

        LiveUpdated::dispatch($live->fresh('creator'), 'ended');

        return true;
    }
}
