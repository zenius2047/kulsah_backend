<?php

namespace App\Notifications\Channels;

use App\Models\NotificationDevice;
use App\Services\FirebaseMessagingService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class FcmChannel
{
    public function __construct(
        private readonly FirebaseMessagingService $firebaseMessagingService,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);
        $tokens = $this->resolveTokens($notifiable);

        if ($tokens === []) {
            return;
        }

        $title = (string) ($payload['title'] ?? 'Notification');
        $body = (string) ($payload['body'] ?? 'You have a new notification.');
        $data = $this->stringifyData($payload['data'] ?? []);

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData($data);

        if (count($tokens) === 1) {
            $this->firebaseMessagingService->messaging()->send(
                $message->withToken($tokens[0])
            );
        } else {
            $this->firebaseMessagingService->messaging()->sendMulticast($message, $tokens);
        }

        Log::debug('FCM notification dispatched.', [
            'notification' => $notification::class,
            'recipient_id' => $this->recipientId($notifiable),
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

    private function stringifyData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(function ($value, $key): array {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                return [(string) $key => (string) ($value ?? '')];
            })
            ->all();
    }

    private function recipientId(object $notifiable): ?int
    {
        return property_exists($notifiable, 'id') ? (int) $notifiable->id : null;
    }
}
