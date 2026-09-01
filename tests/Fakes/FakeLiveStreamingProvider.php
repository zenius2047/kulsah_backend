<?php

namespace Tests\Fakes;

use App\Contracts\LiveStreamingProviderInterface;
use App\Models\LiveSession;
use App\Models\User;

class FakeLiveStreamingProvider implements LiveStreamingProviderInterface
{
    public function channelName(LiveSession $live): string
    {
        return 'live-'.$live->public_id;
    }

    public function credentials(LiveSession $live, User $user, string $role): array
    {
        return [
            'provider' => 'agora',
            'app_id' => 'test-app-id',
            'channel' => $this->channelName($live),
            'uid' => $user->id,
            'token' => 'token-'.$role.'-'.$live->public_id.'-'.$user->id,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
            'role' => $role,
        ];
    }

    public function renewCredentials(LiveSession $live, User $user, string $role): array
    {
        return $this->credentials($live, $user, $role);
    }

    public function startRecording(LiveSession $live): array
    {
        return ['provider' => 'agora', 'status' => 'requested', 'resource_id' => 'resource-'.$live->public_id, 'sid' => 'sid-'.$live->public_id];
    }

    public function stopRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array
    {
        return ['provider' => 'agora', 'status' => 'stopped', 'resource_id' => $resourceId, 'sid' => $sid];
    }

    public function queryRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array
    {
        return ['provider' => 'agora', 'status' => 'ready', 'resource_id' => $resourceId, 'sid' => $sid];
    }

    public function end(LiveSession $live): void
    {
    }
}
