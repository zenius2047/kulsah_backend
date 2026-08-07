<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTicket extends Model
{
    protected $fillable = [
        'event_id',
        'event_ticket_purchase_id',
        'buyer_id',
        'ticket_id',
        'ticket_number',
        'scan_signature',
        'verification_url',
        'qr_code_url',
        'status',
        'verified_at',
        'verified_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ticket_number' => 'integer',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function purchase()
    {
        return $this->belongsTo(EventTicketPurchase::class, 'event_ticket_purchase_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
