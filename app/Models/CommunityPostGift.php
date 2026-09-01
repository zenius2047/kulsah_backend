<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPostGift extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_post_id',
        'sender_id',
        'recipient_user_id',
        'gift_id',
        'kulcoin_transaction_id',
        'quantity',
        'coin_amount',
        'message',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'coin_amount' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function gift()
    {
        return $this->belongsTo(KulCoinGift::class, 'gift_id');
    }

    public function transaction()
    {
        return $this->belongsTo(KulCoinTransaction::class, 'kulcoin_transaction_id');
    }
}
