<?php

namespace App\Http\Resources;

use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ConversationMessage $message */
        $message = $this->resource;
        $currentUserId = (int) $request->user()?->id;
        $reactions = collect($message->reactions ?? [])
            ->groupBy('emoji')
            ->map(function ($items, string $emoji) use ($currentUserId): array {
                return [
                    'emoji' => $emoji,
                    'count' => $items->count(),
                    'user_ids' => $items->pluck('user_id')->map(fn ($id) => (int) $id)->values(),
                    'reacted' => $items->contains('user_id', $currentUserId),
                ];
            })
            ->values();

        return [
            'id' => $message->id,
            'client_message_id' => $message->client_message_id,
            'conversation_id' => $message->conversation_id,
            'sender' => [
                'id' => $message->sender?->id,
                'name' => $message->sender?->name,
                'username' => $message->sender?->username,
                'avatar' => $message->sender?->avatar,
            ],
            'type' => $message->type,
            'body' => $message->body,
            'attachments' => ConversationMessageAttachmentResource::collection($message->attachments ?? collect())->values(),
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'client_message_id' => $message->replyTo->client_message_id,
                'sender_id' => $message->replyTo->sender_id,
            ] : null,
            'metadata' => $message->metadata ?? [],
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'edited_at' => optional($message->edited_at)?->toIso8601String(),
            'deleted_at' => optional($message->deleted_at)?->toIso8601String(),
            'delivery_status' => $message->delivery_status,
            'reactions' => $reactions,
        ];
    }
}


