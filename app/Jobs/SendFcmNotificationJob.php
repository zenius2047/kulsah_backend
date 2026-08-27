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
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationClass,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly array $tokens,
    ) {
        $this->onConnection('redis');
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
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
            } catch (NotFound $throwable) {
                $this->removeInvalidToken($token, $throwable);
            } catch (AuthenticationError $throwable) {
                if ($this->isSenderIdMismatch($throwable)) {
                    $this->removeInvalidToken($token, $throwable);

                    continue;
                }

                $this->reportUnexpectedMessagingFailure($token, $throwable);
                throw $throwable;
            } catch (InvalidMessage $throwable) {
                $this->reportInvalidMessage($throwable);
                throw $throwable;
            } catch (ServerUnavailable|ServerError|QuotaExceeded $throwable) {
                $this->reportTransientFailure($token, $throwable);
                throw $throwable;
            } catch (MessagingException $throwable) {
                $this->reportUnexpectedMessagingFailure($token, $throwable);
                throw $throwable;
            } catch (Throwable $throwable) {
                Log::error('FCM notification delivery failed with an unexpected exception.', [
                    'notification' => $this->notificationClass,
                    'recipient_id' => $this->userId,
                    'token_hash' => $this->fingerprintToken($token),
                    'exception' => get_class($throwable),
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        Log::debug('FCM notification job processed.', [
            'notification' => $this->notificationClass,
            'notification_id' => (string) ($this->data['notification_id'] ?? ''),
            'type' => (string) ($this->data['type'] ?? $this->notificationClass),
            'recipient_id' => $this->userId,
            'token_count' => $tokens->count(),
        ]);
    }

    private function removeInvalidToken(string $token, Throwable $throwable): void
    {
        NotificationDevice::query()->where('token', $token)->delete();

        Log::info('Invalid FCM token removed.', [
            'user_id' => $this->userId,
            'token_hash' => $this->fingerprintToken($token),
            'notification' => $this->notificationClass,
            'exception' => get_class($throwable),
        ]);
    }

    private function reportInvalidMessage(InvalidMessage $throwable): void
    {
        Log::error('FCM notification payload was rejected as invalid.', [
            'notification' => $this->notificationClass,
            'recipient_id' => $this->userId,
            'error' => $throwable->getMessage(),
            'errors' => method_exists($throwable, 'errors') ? $throwable->errors() : [],
        ]);
    }

    private function reportTransientFailure(string $token, Throwable $throwable): void
    {
        Log::warning('FCM notification delivery will be retried.', [
            'notification' => $this->notificationClass,
            'recipient_id' => $this->userId,
            'token_hash' => $this->fingerprintToken($token),
            'exception' => get_class($throwable),
            'error' => $throwable->getMessage(),
        ]);
    }

    private function reportUnexpectedMessagingFailure(string $token, MessagingException $throwable): void
    {
        report($throwable);

        Log::error('FCM notification delivery failed.', [
            'notification' => $this->notificationClass,
            'recipient_id' => $this->userId,
            'token_hash' => $this->fingerprintToken($token),
            'exception' => get_class($throwable),
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

    private function fingerprintToken(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    private function isSenderIdMismatch(AuthenticationError $throwable): bool
    {
        $normalizedMessage = str_replace([' ', '_', '-'], '', strtolower($throwable->getMessage()));

        return str_contains($normalizedMessage, 'senderidmismatch');
    }
}
