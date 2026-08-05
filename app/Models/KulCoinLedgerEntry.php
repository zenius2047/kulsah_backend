<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KulCoinLedgerEntry extends Model
{
    protected $table = 'kulcoin_ledger_entries';

    protected $fillable = [
        'kulcoin_transaction_id',
        'kulcoin_wallet_id',
        'entry_type',
        'balance_bucket',
        'amount_kc',
        'running_balance_kc',
        'narration',
        'metadata',
        'settlement_available_at',
        'settled_at',
    ];

    protected $casts = [
        'amount_kc' => 'integer',
        'running_balance_kc' => 'integer',
        'metadata' => 'array',
        'settlement_available_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function kulCoinTransaction()
    {
        return $this->belongsTo(KulCoinTransaction::class, 'kulcoin_transaction_id');
    }

    public function wallet()
    {
        return $this->belongsTo(KulCoinWallet::class, 'kulcoin_wallet_id');
    }
}
