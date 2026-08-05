<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'reference',
        'type',
        'status',
        'user_id',
        'counterparty_wallet_id',
        'local_currency',
        'local_amount',
        'usd_amount',
        'fx_rate_used',
        'platform_fee_usd',
        'processor_fee_usd',
        'net_usd_amount',
        'description',
        'metadata',
        'performed_by_user_id',
        'processed_at',
    ];

    protected $casts = [
        'local_amount' => 'decimal:4',
        'usd_amount' => 'decimal:4',
        'fx_rate_used' => 'decimal:6',
        'platform_fee_usd' => 'decimal:4',
        'processor_fee_usd' => 'decimal:4',
        'net_usd_amount' => 'decimal:4',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'user_id', 'user_id');
    }

    public function counterpartyWallet()
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }

    public function entries()
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
