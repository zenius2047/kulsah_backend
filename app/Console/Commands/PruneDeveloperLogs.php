<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneDeveloperLogs extends Command
{
    protected $signature = 'developer:prune-logs';

    protected $description = 'Prune expired developer request logs and webhook delivery history';

    public function handle(): int
    {
        $before = now()->subDays(config('developer.log_retention_days', 30));
        DB::table('developer_request_logs')->where('created_at', '<', $before)->delete();
        DB::table('developer_webhook_deliveries')->where('created_at', '<', $before)->where('status', '!=', 'pending')->delete();

        return self::SUCCESS;
    }
}
