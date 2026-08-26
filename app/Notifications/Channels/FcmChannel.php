<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmNotificationJob;
use App\Models\NotificationDevice;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\RealtimePresenceService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
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

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $conversationId = (int) ($data['conversation_id'] ?? 0) ?: null;

        if ($notifiable instanceof User && ! $this->canSendToUser($notifiable, $data, $conversationId)) {
            Log::debug('FCM notification skipped because the recipient is filtering it.', [
                'notification' => $notification::class,
                'recipient_id' => $notifiable->id,
                'conversation_id' => $conversationId,
                'type' => $data['type'] ?? null,
            ]);

            return;
        }

        SendFcmNotificationJob::dispatch(
            userId: (int) $notifiable->id,
            notificationClass: $notification::class,
            title: (string) ($payload['title'] ?? 'Notification'),
            body: (string) ($payload['body'] ?? 'You have a new notification.'),
            data: $data,
            tokens: $tokens,
        );

        Log::debug('FCM notification queued.', [
            'notification' => $notification::class,
            'recipient_id' => $notifiable->id,
            'notification_id' => $data['notification_id'] ?? null,
            'type' => $data['type'] ?? null,
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

    private function canSendToUser(User $user, array $data, ?int $conversationId): bool
    {
        if ($conversationId !== null && $this->presenceService->shouldSuppressPush($user, $conversationId)) {
            return false;
        }

        $preference = $user->notificationPreference()->first();
        $type = (string) ($data['type'] ?? '');

        if (! $this->preferenceAllowsType($preference, $type)) {
            return false;
        }

        if ($this->isQuietHoursActive($preference)) {
            return false;
        }

        return true;
    }

    private function preferenceAllowsType(?NotificationPreference $preference, string $type): bool
    {
        $channel = match (true) {
            str_starts_with($type, 'conversation.message.'),
            str_starts_with($type, 'signal.message_request.'),
            $type === 'video.mentioned' => 'messages',
            str_starts_with($type, 'challenge.') => 'challenge_updates',
            str_starts_with($type, 'marketing.') => 'marketing',
            default => null,
        };

        if ($channel === null) {
            return true;
        }

        $defaults = [
            'messages' => true,
            'challenge_updates' => true,
            'marketing' => false,
        ];

        if (! $preference) {
            return $defaults[$channel] ?? true;
        }

        return (bool) ($preference->{$channel} ?? $defaults[$channel] ?? true);
    }

    private function isQuietHoursActive(?NotificationPreference $preference): bool
    {
        if (! $preference) {
            return false;
        }

        $quietHours = is_array($preference->quiet_hours) ? $preference->quiet_hours : [];

        if (! (bool) ($quietHours['enabled'] ?? false)) {
            return false;
        }

        $timezone = (string) ($quietHours['timezone'] ?? '');
        $start = (string) ($quietHours['start'] ?? '');
        $end = (string) ($quietHours['end'] ?? '');

        if ($timezone === '' || $start === '' || $end === '') {
            return false;
        }

        try {
            $now = Carbon::now($timezone);
            $startAt = Carbon::createFromFormat('H:i', $start, $timezone);
            $endAt = Carbon::createFromFormat('H:i', $end, $timezone);
        } catch (\Throwable) {
            return false;
        }

        if ($startAt->equalTo($endAt)) {
            return true;
        }

        if ($startAt->lessThan($endAt)) {
            return $now->betweenIncluded($startAt, $endAt);
        }

        return $now->greaterThanOrEqualTo($startAt) || $now->lessThanOrEqualTo($endAt);
    }
}


