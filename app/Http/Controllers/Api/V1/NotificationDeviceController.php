<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationDevice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationDeviceController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:64'],
        ]);

        $device = NotificationDevice::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'provider' => $validated['provider'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Notification device registered successfully.',
            'data' => $device->fresh(),
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
}
