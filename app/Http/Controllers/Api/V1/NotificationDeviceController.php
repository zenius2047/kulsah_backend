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
        ]);

        $device = NotificationDevice::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Notification device registered successfully.',
            'data' => $device->fresh(),
        ]);
    }
}
