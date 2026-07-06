<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'account_key',
        'account_name',
        'base_currency',
        'available_balance_usd',
        'pending_balance_usd',
        'held_balance_usd',
        'status',
        'last_ledger_at',
    ];

    protected $casts = [
        'available_balance_usd' => 'decimal:4',
        'pending_balance_usd' => 'decimal:4',
        'held_balance_usd' => 'decimal:4',
        'last_ledger_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'user_id', 'user_id');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }
}
