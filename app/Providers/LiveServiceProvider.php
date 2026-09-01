<?php

namespace App\Providers;

use App\Contracts\LiveStreamingProviderInterface;
use App\Services\AgoraLiveStreamingProvider;
use Illuminate\Support\ServiceProvider;

class LiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LiveStreamingProviderInterface::class, AgoraLiveStreamingProvider::class);
    }
}
