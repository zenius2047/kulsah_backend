<?php

namespace App\Contracts;

use App\Models\LiveSession;
use App\Models\User;

interface LiveStreamingProviderInterface
{
    public function channelName(LiveSession $live): string;

    public function credentials(LiveSession $live, User $user, string $role): array;

    public function renewCredentials(LiveSession $live, User $user, string $role): array;

    public function startRecording(LiveSession $live): array;

    public function stopRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array;

    public function queryRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array;

    public function end(LiveSession $live): void;
}

