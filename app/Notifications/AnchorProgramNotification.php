<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AnchorProgramNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $type, private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'message' => $this->message];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Anchor Kulsans',
            'body' => $this->message,
            'data' => ['type' => $this->type],
        ];
    }
}
