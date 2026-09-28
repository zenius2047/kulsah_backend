<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class UserNotificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 30);
        $perPage = min(max($perPage, 1), 100);
        $notifications = $request->user()->notifications()->latest()->paginate($perPage);

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (DatabaseNotification $notification) => $this->format($notification))->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json(['data' => $this->format($item->fresh())]);
    }

    private function format(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = (string) ($data['type'] ?? $notification->type);

        return [
            'id' => $notification->id,
            'type' => $type,
            'title' => (string) ($data['title'] ?? $data['subject'] ?? $this->titleFromType($type)),
            'message' => (string) ($data['message'] ?? $data['body'] ?? $data['text'] ?? ''),
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'data' => $data,
        ];
    }

    private function titleFromType(string $type): string
    {
        $label = str_replace(['.', '_'], ' ', $type);

        return trim(ucwords($label)) ?: 'Notification';
    }
}
