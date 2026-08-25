<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Services\MessageAttachmentService;
use Illuminate\Http\Request;

class MessageAttachmentController extends Controller
{
    public function __construct(
        private readonly MessageAttachmentService $messageAttachmentService,
    ) {
    }

    public function init(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'kind' => ['sometimes', 'string', 'in:file,image,video,audio,voice,sticker,gif'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $conversation = Conversation::query()->findOrFail($validated['conversation_id']);
        abort_unless($conversation->participants()->where('user_id', $request->user()->id)->exists(), 403, 'You are not a participant in this conversation.');

        $payload = $this->messageAttachmentService->createUploadSession($request->user(), $validated);

        return response()->json([
            'data' => [
                'attachment_id' => $payload['attachment']->id,
                'upload' => $payload['upload'],
            ],
        ], 201);
    }

    public function complete(Request $request, ConversationMessageAttachment $attachment)
    {
        abort_unless((int) $attachment->user_id === (int) $request->user()->id, 403);

        $attachment = $this->messageAttachmentService->completeUpload($attachment, $request->user());

        return response()->json([
            'data' => $attachment,
        ]);
    }
}
