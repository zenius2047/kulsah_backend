<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Video;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class VideoMentionedNotification extends Notification
{
    public function __construct(
        public readonly Video $video,
        public readonly User $actor,
        public readonly array $mentions = [],
        public readonly array $hashtags = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function broadcastType(): string
    {
        return 'video.mentioned';
    }

    public function payload(object $notifiable): array
    {
        return [
            'type' => 'video.mentioned',
            'video_id' => $this->video->id,
            'video_title' => $this->video->title,
            'caption' => $this->video->caption,
            'content_type' => $this->video->content_type,
            'mentioned_by' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'username' => $this->actor->username,
            ],
            'mentions' => $this->mentions,
            'hashtags' => $this->hashtags,
            'notified_user_id' => $notifiable->id ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
