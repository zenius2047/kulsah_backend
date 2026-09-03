<?php

namespace App\Events;

use App\Models\LiveSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveDirectoryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public LiveSession $live, public string $type = 'status')
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('lives')];
    }

    public function broadcastAs(): string
    {
        return 'live.directory.'.$this->type;
    }

    public function broadcastWith(): array
    {
        return [
            'live_id' => $this->live->public_id,
            'creator_id' => (int) $this->live->creator_id,
            'title' => $this->live->title,
            'status' => $this->live->status?->value ?? $this->live->status,
            'category' => $this->live->category,
            'cover_url' => $this->live->cover_url,
            'visibility' => $this->live->visibility,
            'current_viewers' => (int) $this->live->current_viewers,
            'likes_count' => (int) $this->live->likes_count,
            'comments_count' => (int) $this->live->comments_count,
            'gifts_count' => (int) $this->live->gifts_count,
        ];
    }
}