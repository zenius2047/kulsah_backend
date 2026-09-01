<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Video;
use App\Notifications\Channels\FcmChannel;
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
            'title' => 'You were mentioned in a video',
            'body' => sprintf(
                '%s mentioned you in %s.',
                $this->actor->name ?: $this->actor->username,
                $this->video->title ?: 'a video'
            ),
            'data' => $this->payload($notifiable),
        ];
    }

    public function broadcastType(): string
    {
        return 'video.mentioned';
    }

    public function payload(object $notifiable): array
    {
        $recipientId = (int) ($notifiable->id ?? 0);

        return [
            'schema_version' => 1,
            'notification_id' => sprintf('video.mentioned:%d:%d', (int) $this->video->id, $recipientId),
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
            'created_at' => now()->toIso8601String(),
        ];
    }
}
