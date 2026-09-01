<?php

namespace App\Events;

use App\Models\LiveSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public LiveSession $live, public string $type = 'status')
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('lives.'.$this->live->public_id)];
    }

    public function broadcastAs(): string
    {
        return 'live.'.$this->type;
    }

    public function broadcastWith(): array
    {
        return [
            'live_id' => $this->live->public_id,
            'status' => $this->live->status?->value ?? $this->live->status,
            'current_viewers' => (int) $this->live->current_viewers,
            'unique_viewers' => (int) $this->live->unique_viewers,
            'peak_viewers' => (int) $this->live->peak_viewers,
            'likes_count' => (int) $this->live->likes_count,
            'comments_count' => (int) $this->live->comments_count,
            'gifts_count' => (int) $this->live->gifts_count,
            'termination_reason' => $this->live->termination_reason,
        ];
    }
}

