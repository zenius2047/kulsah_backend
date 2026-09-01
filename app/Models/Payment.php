<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'reference', 'idempotency_key', 'provider_reference', 'provider_transaction_id',
        'provider', 'purpose', 'payable_type', 'payable_id', 'amount_minor',
        'currency', 'status', 'provider_status', 'channel', 'metadata',
        'provider_response', 'failure_reason', 'paid_at', 'verified_at', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'provider_response' => 'array',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }
}
