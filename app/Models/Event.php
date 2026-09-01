<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'category',
        'venue_type',
        'venue_name',
        'venue_address',
        'meeting_url',
        'starts_at',
        'ends_at',
        'timezone',
        'capacity',
        'currency',
        'cover_image_disk',
        'cover_image_key',
        'cover_image_url',
        'ticket_types',
        'status',
        'tickets_sold',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'tickets_sold' => 'integer',
            'ticket_types' => 'array',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function purchases()
    {
        return $this->hasMany(EventTicketPurchase::class);
    }

    public function tickets()
    {
        return $this->hasMany(EventTicket::class);
    }
}
