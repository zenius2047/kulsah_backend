<?php

use App\Jobs\SyncChallengeLifecycle;
use App\Models\Challenge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wallets:settle-pending-funds')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('live:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notification-devices:prune-stale')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function (): void {
    Challenge::query()
        ->whereIn('status', ['scheduled', 'active', 'submissions_closed', 'voting_closed', 'judging', 'integrity_review'])
        ->orderBy('id')
        ->pluck('id')
        ->each(fn (int $id) => SyncChallengeLifecycle::dispatch($id));
})->everyMinute()->name('challenges:sync-lifecycle')->withoutOverlapping()->onOneServer();



