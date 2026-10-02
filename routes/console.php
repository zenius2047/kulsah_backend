<?php

use App\Jobs\SyncChallengeLifecycle;
use App\Models\Challenge;
use App\Models\CommunityPost;
use App\Models\VoiceCall;
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

Schedule::command('feed:refresh-trending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notification-devices:prune-stale')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function (): void {
    CommunityPost::query()
        ->where('status', 'scheduled')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->update([
            'status' => 'published',
            'published_at' => now(),
            'last_activity_at' => now(),
            'updated_at' => now(),
        ]);
})->everyMinute()->name('community:publish-scheduled')->withoutOverlapping()->onOneServer();

Schedule::call(function (): void {
    VoiceCall::query()
        ->where('status', 'ringing')
        ->where('created_at', '<=', now()->subMinute())
        ->update([
            'status' => 'missed',
            'ended_at' => now(),
        ]);
})->everyMinute()->name('voice-calls:expire-missed')->withoutOverlapping()->onOneServer();

Schedule::call(function (): void {
    Challenge::query()
        ->whereIn('status', ['scheduled', 'active', 'submissions_closed', 'voting_closed', 'judging', 'integrity_review'])
        ->orderBy('id')
        ->pluck('id')
        ->each(fn (int $id) => SyncChallengeLifecycle::dispatch($id));
})->everyMinute()->name('challenges:sync-lifecycle')->withoutOverlapping()->onOneServer();

