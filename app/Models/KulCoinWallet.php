<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KulCoinWallet extends Model
{
    protected $table = 'kulcoin_wallets';

    protected $fillable = [
        'user_id',
        'account_key',
        'account_name',
        'currency_code',
        'available_balance_kc',
        'bonus_balance_kc',
        'status',
        'last_ledger_at',
    ];

    protected $casts = [
        'available_balance_kc' => 'integer',
        'bonus_balance_kc' => 'integer',
        'last_ledger_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(KulCoinTransaction::class, 'user_id', 'user_id');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(KulCoinLedgerEntry::class, 'kulcoin_wallet_id');
    }
}
