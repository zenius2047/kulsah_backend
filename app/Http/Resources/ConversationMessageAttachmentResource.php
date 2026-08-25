<?php

namespace App\Http\Resources;

use App\Models\ConversationMessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMessageAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ConversationMessageAttachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => $attachment->id,
            'conversation_message_id' => $attachment->conversation_message_id,
            'conversation_id' => $attachment->conversation_id,
            'kind' => $attachment->kind,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'url' => $attachment->source_url,
            'status' => $attachment->status,
            'uploaded_at' => optional($attachment->uploaded_at)?->toIso8601String(),
            'attached_at' => optional($attachment->attached_at)?->toIso8601String(),
        ];
    }
}
