<?php

namespace App\Console\Commands;

use App\Enums\LiveStatus;
use App\Models\LiveSession;
use App\Services\LiveSessionService;
use Illuminate\Console\Command;

class ReconcileLiveSessions extends Command
{
    protected $signature = 'live:reconcile';

    protected $description = 'End Live sessions that stopped sending heartbeats.';

    public function handle(LiveSessionService $service): int
    {
        $reconciled = 0;

        LiveSession::query()
            ->whereIn('status', [LiveStatus::STARTING, LiveStatus::LIVE, LiveStatus::RECONNECTING])
            ->orderBy('id')
            ->each(function (LiveSession $live) use ($service, &$reconciled): void {
                if ($service->reconcileStaleLive($live)) {
                    $reconciled++;
                }
            });

        $this->info("Reconciled {$reconciled} stale Live session(s).");

        return self::SUCCESS;
    }
}