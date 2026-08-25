<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmNotificationJob;
use App\Models\NotificationDevice;
use App\Services\RealtimePresenceService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    public function __construct(
        private readonly RealtimePresenceService $presenceService,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);
        $tokens = $this->resolveTokens($notifiable);

        if ($tokens === [] || ! property_exists($notifiable, 'id')) {
            return;
        }

        $conversationId = (int) ($payload['data']['conversation_id'] ?? 0) ?: null;
        if ($notifiable instanceof \App\Models\User && $this->presenceService->shouldSuppressPush($notifiable, $conversationId)) {
            Log::debug('FCM notification skipped because recipient is active in-app.', [
                'notification' => $notification::class,
                'recipient_id' => $notifiable->id,
                'conversation_id' => $conversationId,
            ]);

            return;
        }

        SendFcmNotificationJob::dispatch(
            userId: (int) $notifiable->id,
            notificationClass: $notification::class,
            title: (string) ($payload['title'] ?? 'Notification'),
            body: (string) ($payload['body'] ?? 'You have a new notification.'),
            data: $payload['data'] ?? [],
            tokens: $tokens,
        );

        Log::debug('FCM notification queued.', [
            'notification' => $notification::class,
            'recipient_id' => $notifiable->id,
            'token_count' => count($tokens),
        ]);
    }

    private function resolveTokens(object $notifiable): array
    {
        if (method_exists($notifiable, 'notificationDevices')) {
            $devices = $notifiable->notificationDevices()->get();
        } elseif (isset($notifiable->notificationDevices) && $notifiable->notificationDevices instanceof \Illuminate\Support\Collection) {
            $devices = $notifiable->notificationDevices;
        } else {
            $devices = collect();
        }

        return $devices
            ->map(fn (NotificationDevice $device) => trim((string) $device->token))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
