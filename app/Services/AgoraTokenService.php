<?php

namespace App\Services;

use App\Contracts\LiveStreamingProviderInterface;
use App\Models\LiveSession;
use App\Models\User;

class AgoraTokenService
{
    public function __construct(private readonly LiveStreamingProviderInterface $provider)
    {
    }

    public function generateBroadcasterToken(LiveSession $live, User $user): array
    {
        return $this->provider->credentials($live, $user, 'broadcaster');
    }

    public function generateAudienceToken(LiveSession $live, User $user): array
    {
        return $this->provider->credentials($live, $user, 'audience');
    }

    public function generateCoHostToken(LiveSession $live, User $user): array
    {
        return $this->provider->credentials($live, $user, 'broadcaster');
    }

    public function renewToken(LiveSession $live, User $user, string $role): array
    {
        return $this->provider->renewCredentials($live, $user, $role);
    }
}

