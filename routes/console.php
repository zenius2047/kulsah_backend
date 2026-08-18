<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SyncChallengeLifecycle;
use App\Models\Challenge;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wallets:settle-pending-funds')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function (): void {
    Challenge::query()
        ->whereIn('status', ['approved', 'scheduled', 'active', 'submissions_closed', 'voting_closed', 'judging', 'integrity_review'])
        ->orderBy('id')
        ->pluck('id')
        ->each(fn (int $id) => SyncChallengeLifecycle::dispatch($id));
})->everyMinute()->name('challenges:sync-lifecycle')->withoutOverlapping()->onOneServer();
