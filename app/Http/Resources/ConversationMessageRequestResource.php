<?php

namespace App\Http\Resources;

use App\Models\ConversationMessageRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMessageRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ConversationMessageRequest $requestModel */
        $requestModel = $this->resource;

        return [
            'id' => $requestModel->id,
            'conversation_id' => $requestModel->conversation_id,
            'sender' => [
                'id' => $requestModel->sender?->id,
                'name' => $requestModel->sender?->name,
                'username' => $requestModel->sender?->username,
                'avatar' => $requestModel->sender?->avatar,
            ],
            'receiver_id' => $requestModel->receiver_id,
            'status' => $requestModel->status,
            'intro_client_message_id' => $requestModel->intro_client_message_id,
            'intro_type' => $requestModel->intro_type,
            'intro_body' => $requestModel->intro_body,
            'intro_metadata' => $requestModel->intro_metadata ?? [],
            'accepted_at' => optional($requestModel->accepted_at)?->toIso8601String(),
            'declined_at' => optional($requestModel->declined_at)?->toIso8601String(),
            'blocked_at' => optional($requestModel->blocked_at)?->toIso8601String(),
            'cancelled_at' => optional($requestModel->cancelled_at)?->toIso8601String(),
            'cooldown_until' => optional($requestModel->cooldown_until)?->toIso8601String(),
            'created_at' => optional($requestModel->created_at)?->toIso8601String(),
            'updated_at' => optional($requestModel->updated_at)?->toIso8601String(),
        ];
    }
}
