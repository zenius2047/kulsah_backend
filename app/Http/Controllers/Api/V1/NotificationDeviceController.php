<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationDevice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationDeviceController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in(['fcm', 'apns'])],
            'app_version' => ['nullable', 'string', 'max:64'],
        ]);

        $this->validatePlatformProviderCombo($validated['platform'], $validated['provider']);

        $device = NotificationDevice::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'provider' => $validated['provider'],
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ]
        )->fresh();

        return response()->json([
            'message' => 'Notification device registered successfully.',
            'data' => [
                'notification_device_id' => $device->id,
                'platform' => $device->platform,
                'provider' => $device->provider,
                'device_name' => $device->device_name,
                'app_version' => $device->app_version,
                'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, NotificationDevice $notificationDevice)
    {
        if ((int) $notificationDevice->user_id !== (int) $request->user()->id) {
            abort(403, 'You are not allowed to revoke this notification device.');
        }

        $notificationDevice->delete();

        return response()->json([
            'message' => 'Notification device revoked successfully.',
        ]);
    }

    private function validatePlatformProviderCombo(string $platform, string $provider): void
    {
        $allowed = match ($platform) {
            'android', 'web' => ['fcm'],
            'ios' => ['fcm', 'apns'],
            default => [],
        };

        if (! in_array($provider, $allowed, true)) {
            throw ValidationException::withMessages([
                'provider' => sprintf('The %s platform only supports %s for notification delivery.', $platform, implode(' or ', $allowed)),
            ]);
        }
    }
}
