<?php

namespace App\Services;

use App\Events\LiveUpdated;
use App\Models\KulCoinGift;
use App\Models\KulCoinTransaction;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LiveGiftService
{
    public function __construct(private readonly LiveAuthorizationService $authorization, private readonly KulCoinService $kulCoinService)
    {
    }

    public function send(LiveSession $live, User $sender, KulCoinGift $gift, int $quantity, array $data = []): KulCoinTransaction
    {
        if (! $live->gifts_enabled) {
            throw ValidationException::withMessages(['live' => 'Gifts are disabled.']);
        }

        $this->authorization->assertViewerCanJoin($sender, $live);

        $transaction = $this->kulCoinService->sendGift(
            sender: $sender,
            creator: $live->creator,
            gift: $gift,
            quantity: $quantity,
            data: $data,
            actor: $sender
        );

        $live->forceFill([
            'gifts_count' => (int) $live->gifts_count + $quantity,
            'gift_value_kc' => (int) $live->gift_value_kc + ((int) $gift->coin_cost * $quantity),
        ])->saveQuietly();

        LiveUpdated::dispatch($live->fresh('creator'), 'gift_created');

        return $transaction;
    }
}

