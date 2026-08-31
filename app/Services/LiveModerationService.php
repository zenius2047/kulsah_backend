<?php

namespace App\Services;

use App\Models\LiveModerationAction;
use App\Models\LiveSession;
use App\Models\User;

class LiveModerationService
{
    public function record(
        LiveSession $live,
        User $actor,
        User $target,
        string $action,
        ?string $reason = null,
        ?int $durationSeconds = null,
        ?\DateTimeInterface $expiresAt = null
    ): LiveModerationAction {
        return LiveModerationAction::query()->create([
            'live_session_id' => $live->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'action' => $action,
            'reason' => $reason,
            'duration_seconds' => $durationSeconds,
            'expires_at' => $expiresAt,
        ]);
    }
}

