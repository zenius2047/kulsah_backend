<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReverseRefundTransaction implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $walletTransactionId,
        public ?int $actorUserId = null,
        public ?string $reason = null
    ) {
    }

    public function handle(WalletService $walletService): void
    {
        $transaction = WalletTransaction::query()
            ->with(['entries.wallet', 'wallet', 'counterpartyWallet'])
            ->findOrFail($this->walletTransactionId);

        $actor = $this->actorUserId
            ? User::query()->find($this->actorUserId)
            : null;

        $walletService->reverseWalletTransaction($transaction, $this->reason, $actor);
    }
}
