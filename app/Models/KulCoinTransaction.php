<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KulCoinTransaction extends Model
{
    protected $table = 'kulcoin_transactions';

    protected $fillable = [
        'reference',
        'idempotency_key',
        'type',
        'status',
        'user_id',
        'counterparty_wallet_id',
        'package_id',
        'gift_id',
        'local_currency',
        'local_amount',
        'usd_amount',
        'coin_amount',
        'bonus_coin_amount',
        'net_coin_amount',
        'description',
        'metadata',
        'performed_by_user_id',
        'processed_at',
    ];

    protected $casts = [
        'local_amount' => 'decimal:4',
        'usd_amount' => 'decimal:4',
        'coin_amount' => 'integer',
        'bonus_coin_amount' => 'integer',
        'net_coin_amount' => 'integer',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(KulCoinWallet::class, 'user_id', 'user_id');
    }

    public function counterpartyWallet()
    {
        return $this->belongsTo(KulCoinWallet::class, 'counterparty_wallet_id');
    }

    public function package()
    {
        return $this->belongsTo(KulCoinPackage::class);
    }

    public function gift()
    {
        return $this->belongsTo(KulCoinGift::class);
    }

    public function entries()
    {
        return $this->hasMany(KulCoinLedgerEntry::class, 'kulcoin_transaction_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
