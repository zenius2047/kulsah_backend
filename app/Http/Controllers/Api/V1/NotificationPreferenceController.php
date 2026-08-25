<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $preferences = $this->resolvePreferences($request->user());

        return response()->json([
            'data' => $this->formatPreferences($preferences),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'messages' => ['sometimes', 'boolean'],
            'challenge_updates' => ['sometimes', 'boolean'],
            'live_events' => ['sometimes', 'boolean'],
            'commerce' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.start' => ['required_with:quiet_hours.enabled', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours.enabled', 'date_format:H:i'],
            'quiet_hours.timezone' => ['required_with:quiet_hours.enabled', 'timezone'],
        ]);

        $preferences = $this->resolvePreferences($request->user());

        foreach (['messages', 'challenge_updates', 'live_events', 'commerce', 'marketing'] as $key) {
            if (array_key_exists($key, $validated)) {
                $preferences->{$key} = (bool) $validated[$key];
            }
        }

        if (array_key_exists('quiet_hours', $validated)) {
            $existingQuietHours = is_array($preferences->quiet_hours) ? $preferences->quiet_hours : [];
            $incomingQuietHours = $validated['quiet_hours'] ?? [];
            $preferences->quiet_hours = array_merge($existingQuietHours, $incomingQuietHours);
        }

        $preferences->save();

        return response()->json([
            'data' => $this->formatPreferences($preferences->fresh()),
        ]);
    }

    private function resolvePreferences($user): NotificationPreference
    {
        return $user->notificationPreference()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'messages' => true,
            'challenge_updates' => true,
            'live_events' => true,
            'commerce' => true,
            'marketing' => false,
            'quiet_hours' => null,
        ]);
    }

    private function formatPreferences(NotificationPreference $preferences): array
    {
        return [
            'messages' => (bool) $preferences->messages,
            'challenge_updates' => (bool) $preferences->challenge_updates,
            'live_events' => (bool) $preferences->live_events,
            'commerce' => (bool) $preferences->commerce,
            'marketing' => (bool) $preferences->marketing,
            'quiet_hours' => $preferences->quiet_hours,
        ];
    }
}
