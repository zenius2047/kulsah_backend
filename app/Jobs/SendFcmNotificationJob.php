<?php

namespace App\Jobs;

use App\Models\NotificationDevice;
use App\Services\FirebaseMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationClass,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly array $tokens,
    ) {
        $this->onQueue(config('queue.default', 'redis'));
    }

    public function handle(FirebaseMessagingService $firebaseMessagingService): void
    {
        $tokens = collect($this->tokens)
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($this->title, $this->body))
            ->withData($this->stringifyData($this->data));

        foreach ($tokens as $token) {
            try {
                $firebaseMessagingService->messaging()->send($message->withToken($token));
            } catch (Throwable $throwable) {
                $this->handleFailedToken($token, $throwable);
            }
        }

        Log::debug('FCM notification job processed.', [
            'notification' => $this->notificationClass,
            'recipient_id' => $this->userId,
            'token_count' => $tokens->count(),
        ]);
    }

    private function handleFailedToken(string $token, Throwable $throwable): void
    {
        $message = strtolower($throwable->getMessage());

        if (str_contains($message, 'not registered')
            || str_contains($message, 'registration token')
            || str_contains($message, 'requested entity was not found')
            || str_contains($message, 'invalid registration')
        ) {
            NotificationDevice::query()->where('token', $token)->delete();

            Log::warning('Invalid FCM token removed.', [
                'user_id' => $this->userId,
                'token' => $token,
                'notification' => $this->notificationClass,
            ]);

            return;
        }

        Log::warning('FCM notification delivery failed.', [
            'notification' => $this->notificationClass,
            'recipient_id' => $this->userId,
            'token' => $token,
            'error' => $throwable->getMessage(),
        ]);
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
}
