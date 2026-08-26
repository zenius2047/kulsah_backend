<?php

namespace App\Console\Commands;

use App\Models\NotificationDevice;
use Illuminate\Console\Command;

class PruneStaleNotificationDevices extends Command
{
    protected $signature = 'notification-devices:prune-stale {--days=90 : Remove devices that have not refreshed within this many days}';

    protected $description = 'Remove stale notification device registrations that have not refreshed recently.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = NotificationDevice::query()
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $cutoff);
            })
            ->delete();

        $this->info(sprintf('Pruned %d stale notification devices older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}
