<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTicketPurchase extends Model
{
    protected $fillable = [
        'event_id',
        'buyer_id',
        'ticket_type_code',
        'ticket_type_name',
        'ticket_type_snapshot',
        'quantity',
        'unit_price',
        'total_amount',
        'currency',
        'status',
        'reference',
        'idempotency_key',
        'metadata',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'ticket_type_snapshot' => 'array',
            'quantity' => 'integer',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'metadata' => 'array',
            'purchased_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function tickets()
    {
        return $this->hasMany(EventTicket::class, 'event_ticket_purchase_id');
    }
}
