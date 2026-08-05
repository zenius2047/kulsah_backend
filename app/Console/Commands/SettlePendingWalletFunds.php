<?php

namespace App\Console\Commands;

use App\Services\WalletService;
use Illuminate\Console\Command;

class SettlePendingWalletFunds extends Command
{
    protected $signature = 'wallets:settle-pending-funds {--dry-run : Preview what would be settled without changing balances}';

    protected $description = 'Release matured pending wallet funds into available balance after the holding period.';

    public function __construct(private readonly WalletService $walletService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run mode is enabled. No balances will be changed.');

            $summary = [
                'wallets' => 0,
                'entries' => 0,
                'amount_usd' => 0.0,
            ];
        } else {
            $summary = $this->walletService->settlePendingFunds();
        }

        $this->info(sprintf(
            'Settlement complete. Wallets: %d, Entries: %d, Amount USD: %s',
            $summary['wallets'],
            $summary['entries'],
            number_format((float) $summary['amount_usd'], 4, '.', '')
        ));

        return self::SUCCESS;
    }
}
