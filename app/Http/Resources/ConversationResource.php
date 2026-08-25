<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->resource;
        $currentUserId = (int) $request->user()?->id;
        $participant = $conversation->participants->firstWhere('user_id', $currentUserId)
            ?? $conversation->participants->first();

        return [
            'id' => $conversation->id,
            'conversation_key' => $conversation->conversation_key,
            'context_type' => $conversation->context_type,
            'context_id' => $conversation->context_id,
            'is_group' => (bool) $conversation->is_group,
            'participants' => $conversation->participants->map(function ($participant): array {
                return [
                    'id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'role' => $participant->role,
                    'unread_count' => (int) $participant->unread_count,
                    'last_read_message_id' => $participant->last_read_message_id,
                    'last_read_at' => optional($participant->last_read_at)?->toIso8601String(),
                    'archived_at' => optional($participant->archived_at)?->toIso8601String(),
                    'user' => [
                        'id' => $participant->user?->id,
                        'name' => $participant->user?->name,
                        'username' => $participant->user?->username,
                        'avatar' => $participant->user?->avatar,
                    ],
                ];
            })->values(),
            'last_message' => $conversation->lastMessage ? new ConversationMessageResource($conversation->lastMessage) : null,
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            'unread_count' => (int) ($participant?->unread_count ?? 0),
            'archived_at' => optional($participant?->archived_at)?->toIso8601String(),
            'created_at' => optional($conversation->created_at)?->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)?->toIso8601String(),
        ];
    }
}
