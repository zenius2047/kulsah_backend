<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class KycApplicationDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $reference,
        private readonly string $status,
        private readonly ?string $message,
    ) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'kyc.application.updated',
            'title' => 'Identity verification update',
            'body' => $this->message ?: 'Your identity verification application status changed to '.str_replace('_', ' ', $this->status).'.',
            'application_reference' => $this->reference,
            'status' => $this->status,
        ];
    }
}
