<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    protected $fillable = [
        'wallet_transaction_id',
        'wallet_id',
        'entry_type',
        'balance_bucket',
        'amount_usd',
        'running_balance_usd',
        'narration',
        'metadata',
        'settlement_available_at',
        'settled_at',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:4',
        'running_balance_usd' => 'decimal:4',
        'metadata' => 'array',
        'settlement_available_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
