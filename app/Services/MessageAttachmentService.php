<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MessageAttachmentService
{
    public function __construct(
        private readonly VideoStorageService $videoStorageService,
    ) {
    }

    public function createUploadSession(User $user, array $data): array
    {
        $stored = $this->videoStorageService->createTemporaryUploadInDirectory(
            userId: (int) $user->id,
            originalName: $data['file_name'] ?? null,
            mimeType: $data['mime_type'] ?? null,
            directory: config('messages.upload_directory', 'messages/attachments')
        );

        $attachment = ConversationMessageAttachment::create([
            'conversation_id' => (int) $data['conversation_id'],
            'user_id' => (int) $user->id,
            'kind' => $data['kind'] ?? 'file',
            'file_name' => $data['file_name'],
            'mime_type' => $data['mime_type'] ?? null,
            'size' => $data['size'] ?? null,
            'disk' => $stored['disk'],
            'source_key' => $stored['source_key'],
            'source_url' => $stored['source_url'],
            'status' => 'initialized',
            'metadata' => $data['metadata'] ?? [],
        ]);

        return [
            'attachment' => $attachment,
            'upload' => [
                'method' => 'PUT',
                'url' => $stored['upload_url'],
                'headers' => $stored['upload_headers'],
                'expires_at' => $stored['expires_at'],
            ],
        ];
    }

    public function completeUpload(ConversationMessageAttachment $attachment, User $user): ConversationMessageAttachment
    {
        $attachment = ConversationMessageAttachment::query()
            ->whereKey($attachment->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $storage = Storage::disk($attachment->disk);

        if (! $storage->exists($attachment->source_key)) {
            throw new RuntimeException('The uploaded message attachment is not available yet.');
        }

        $attachment->update([
            'status' => 'uploaded',
            'uploaded_at' => now(),
            'source_url' => $this->videoStorageService->resolveAccessibleUrl($attachment->disk, $attachment->source_key),
        ]);

        return $attachment->fresh();
    }
}
