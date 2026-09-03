<?php

namespace App\Notifications;

use App\Models\LiveSession;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class CreatorLiveStartedNotification extends Notification
{
    public function __construct(public readonly LiveSession $live)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => sprintf('%s is live now', $this->live->creator?->name ?: $this->live->creator?->username ?: 'A creator you subscribe to'),
            'body' => $this->live->title ?: 'Join the live stream now.',
            'data' => $this->payload($notifiable),
        ];
    }

    public function broadcastType(): string
    {
        return 'live.started';
    }

    public function payload(object $notifiable): array
    {
        $creator = $this->live->creator;
        $recipientId = (int) ($notifiable->id ?? 0);

        return [
            'schema_version' => 1,
            'notification_id' => sprintf('live.started:%d:%d', $this->live->id, $recipientId),
            'type' => 'live.started',
            'title' => $this->live->title ?: 'Live stream started',
            'body' => sprintf('%s is live now.', $creator?->name ?: $creator?->username ?: 'A creator you subscribe to'),
            'live_id' => $this->live->public_id,
            'creator_id' => (int) $this->live->creator_id,
            'creator_name' => $creator?->name,
            'creator_username' => $creator?->username,
            'category' => $this->live->category,
            'cover_url' => $this->live->cover_url,
            'created_at' => now()->toIso8601String(),
        ];
    }
}