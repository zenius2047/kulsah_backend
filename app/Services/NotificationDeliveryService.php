<?php

namespace App\Services;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationDeliveryService
{
    public function sendNow(iterable|object $notifiables, Notification $notification): void
    {
        NotificationFacade::sendNow($notifiables, $notification);
    }

    public function send(iterable|object $notifiables, Notification $notification): void
    {
        NotificationFacade::send($notifiables, $notification);
    }

    public function normalizeRecipients(iterable|object $notifiables): Collection
    {
        return $notifiables instanceof Collection
            ? $notifiables->filter()
            : Collection::make($notifiables)->filter();
    }
}
