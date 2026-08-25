<?php

namespace App\Services;

use App\Events\ConversationMessageCreated;
use App\Events\ConversationTypingStarted;
use App\Events\ConversationTypingStopped;
use App\Events\ConversationUnreadCountUpdated;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Notifications\ConversationMessageNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(
        private readonly MessageAttachmentService $messageAttachmentService,
    ) {
    }

    public function listConversations(User $user, array $filters, int $page = 1, int $perPage = 30): LengthAwarePaginator
    {
        $archived = filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $unreadOnly = filter_var($filters['unread_only'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Conversation::query()
            ->with([
                'participants.user',
                'lastMessage.sender',
                'lastMessage.attachments',
                'lastMessage.reactions',
            ])
            ->whereHas('participants', fn ($participantQuery) => $participantQuery->where('user_id', $user->id));

        $query->whereHas('participants', function ($participantQuery) use ($user, $archived, $unreadOnly): void {
            $participantQuery->where('user_id', $user->id);

            if ($archived === true) {
                $participantQuery->whereNotNull('archived_at');
            } elseif ($archived === false) {
                $participantQuery->whereNull('archived_at');
            }

            if ($unreadOnly === true) {
                $participantQuery->where('unread_count', '>', 0);
            }
        });

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('conversation_key', 'like', '%'.$search.'%')
                    ->orWhereHas('lastMessage', fn ($messageQuery) => $messageQuery->where('body', 'like', '%'.$search.'%'))
                    ->orWhereHas('participants.user', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%'.$search.'%')
                            ->orWhere('username', 'like', '%'.$search.'%');
                    });
            });
        }

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function createConversation(User $actor, array $data): Conversation
    {
        $participantIds = collect($data['participant_ids'] ?? [])
            ->map(static fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0 && $value !== (int) $actor->id)
            ->unique()
            ->values();

        if ($participantIds->isEmpty()) {
            throw ValidationException::withMessages([
                'participant_ids' => 'At least one participant is required.',
            ]);
        }

        $contextType = isset($data['context_type']) ? trim((string) $data['context_type']) : null;
        $contextId = isset($data['context_id']) ? (int) $data['context_id'] : null;
        $conversationKey = $this->buildConversationKey($actor->id, $participantIds->all(), $contextType, $contextId);

        return DB::transaction(function () use ($actor, $participantIds, $contextType, $contextId, $conversationKey, $data): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['conversation_key' => $conversationKey],
                [
                    'created_by_user_id' => $actor->id,
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'is_group' => $participantIds->count() > 1,
                    'last_message_at' => null,
                    'last_message_id' => null,
                ]
            );

            $participantUsers = User::query()
                ->whereIn('id', array_merge([$actor->id], $participantIds->all()))
                ->get()
                ->keyBy('id');

            foreach (array_merge([$actor->id], $participantIds->all()) as $userId) {
                ConversationParticipant::updateOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $userId],
                    [
                        'role' => $userId === (int) $actor->id ? 'owner' : 'participant',
                        'unread_count' => 0,
                        'archived_at' => null,
                    ]
                );
            }

            $conversation->load(['participants.user', 'lastMessage.sender', 'lastMessage.attachments', 'lastMessage.reactions']);

            $initialMessage = $data['initial_message'] ?? null;
            if (is_array($initialMessage) && ! empty($initialMessage)) {
                $this->sendMessage($conversation, $actor, [
                    'client_message_id' => $initialMessage['client_message_id'] ?? (string) Str::uuid(),
                    'type' => $initialMessage['type'] ?? 'text',
                    'body' => $initialMessage['body'] ?? null,
                    'attachment_ids' => $initialMessage['attachment_ids'] ?? [],
                    'reply_to_message_id' => $initialMessage['reply_to_message_id'] ?? null,
                    'metadata' => $initialMessage['metadata'] ?? [],
                    'idempotency_key' => $initialMessage['idempotency_key'] ?? null,
                ]);
                $conversation->refresh()->load(['participants.user', 'lastMessage.sender', 'lastMessage.attachments', 'lastMessage.reactions']);
            }

            return $conversation;
        });
    }

    public function getMessages(Conversation $conversation, User $user, ?int $beforeMessageId = null, int $perPage = 50): array
    {
        $this->ensureParticipant($conversation, $user->id);

        $query = ConversationMessage::query()
            ->with(['sender', 'attachments', 'replyTo.sender', 'reactions'])
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id');

        if ($beforeMessageId !== null) {
            $query->where('id', '<', $beforeMessageId);
        }

        $messages = $query->limit($perPage + 1)->get();
        $hasMore = $messages->count() > $perPage;

        if ($hasMore) {
            $messages = $messages->slice(0, $perPage);
        }

        $messages = $messages->reverse()->values();

        return [
            'messages' => $messages,
            'has_more' => $hasMore,
            'next_before_message_id' => $messages->isNotEmpty() ? (int) $messages->first()->id : null,
        ];
    }

    public function sendMessage(Conversation $conversation, User $user, array $data): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $user, $data): ConversationMessage {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $participant = $this->ensureParticipant($conversation, $user->id, true);

            $idempotencyKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
            if ($idempotencyKey !== '') {
                $existing = ConversationMessage::query()
                    ->with(['sender', 'attachments', 'replyTo.sender', 'reactions'])
                    ->where('conversation_id', $conversation->id)
                    ->where('sender_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $attachmentIds = collect($data['attachment_ids'] ?? [])
                ->map(static fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values();

            $attachments = ConversationMessageAttachment::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->whereIn('id', $attachmentIds->all())
                ->where('status', 'uploaded')
                ->whereNull('conversation_message_id')
                ->get();

            if ($attachments->count() !== $attachmentIds->count()) {
                throw ValidationException::withMessages([
                    'attachment_ids' => 'One or more attachments are invalid or unavailable.',
                ]);
            }

            $replyToMessageId = isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null;
            if ($replyToMessageId) {
                $replyToExists = ConversationMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereKey($replyToMessageId)
                    ->exists();

                if (! $replyToExists) {
                    throw ValidationException::withMessages([
                        'reply_to_message_id' => 'The reply message must belong to this conversation.',
                    ]);
                }
            }

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'client_message_id' => $data['client_message_id'] ?? (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'type' => $data['type'] ?? 'text',
                'body' => $data['body'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'reply_to_message_id' => $replyToMessageId,
                'delivery_status' => 'sent',
            ]);

            if ($attachments->isNotEmpty()) {
                ConversationMessageAttachment::query()
                    ->whereIn('id', $attachments->pluck('id')->all())
                    ->update([
                        'conversation_message_id' => $message->id,
                        'attached_at' => now(),
                    ]);
            }

            $conversation->update([
                'last_message_id' => $message->id,
                'last_message_at' => now(),
            ]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $user->id)
                ->update([
                    'unread_count' => DB::raw('unread_count + 1'),
                ]);

            $message->load(['sender', 'attachments', 'replyTo.sender', 'reactions']);
            $conversation->load(['participants.user', 'lastMessage.sender', 'lastMessage.attachments', 'lastMessage.reactions']);

            DB::afterCommit(function () use ($conversation, $message, $user): void {
                $recipientIds = $conversation->participants
                    ->pluck('user_id')
                    ->reject(fn ($recipientId) => (int) $recipientId === (int) $user->id)
                    ->map(fn ($recipientId) => (int) $recipientId)
                    ->values();

                if ($recipientIds->isEmpty()) {
                    event(new ConversationMessageCreated($message, (string) Str::uuid(), now()->toIso8601String(), $user->id));
                    return;
                }

                $recipients = User::query()->whereIn('id', $recipientIds->all())->get();
                $summary = $this->conversationSummary($conversation);

                Notification::sendNow(
                    $recipients,
                    new ConversationMessageNotification(
                        message: $message->fresh(['sender', 'attachments', 'replyTo.sender', 'reactions']),
                        sender: $user,
                        conversationSummary: $summary,
                    )
                );

                foreach ($recipientIds as $recipientId) {
                    $participant = $conversation->participants->firstWhere('user_id', (int) $recipientId);
                    event(new ConversationUnreadCountUpdated(
                        conversation: $conversation,
                        userId: (int) $recipientId,
                        unreadCount: (int) ($participant?->unread_count ?? 0),
                        eventId: (string) Str::uuid(),
                        occurredAt: now()->toIso8601String(),
                    ));
                }

                event(new ConversationMessageCreated(
                    message: $message->fresh(['sender', 'attachments', 'replyTo.sender', 'reactions']),
                    eventId: (string) Str::uuid(),
                    occurredAt: now()->toIso8601String(),
                    userId: (int) $user->id,
                ));

                event(new ConversationUpdated(
                    conversation: $conversation,
                    eventType: 'conversation.updated',
                    eventId: (string) Str::uuid(),
                    occurredAt: now()->toIso8601String(),
                    userId: (int) $user->id,
                    changes: [
                        'last_message_id' => $message->id,
                        'last_message_at' => now()->toIso8601String(),
                    ],
                ));
            });

            return $message->fresh(['sender', 'attachments', 'replyTo.sender', 'reactions']);
        });
    }

    public function markConversationRead(Conversation $conversation, User $user, int $lastReadMessageId, ?string $readAt = null): ConversationParticipant
    {
        return DB::transaction(function () use ($conversation, $user, $lastReadMessageId, $readAt): ConversationParticipant {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $participant = $this->ensureParticipant($conversation, $user->id, true);
            $readAtValue = $readAt ? now()->parse($readAt) : now();

            ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '<=', $lastReadMessageId)
                ->where('sender_id', '!=', $user->id)
                ->update(['delivery_status' => 'read']);

            $participant->update([
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at' => $readAtValue,
                'unread_count' => 0,
            ]);

            DB::afterCommit(function () use ($conversation, $user, $participant, $lastReadMessageId, $readAtValue): void {
                event(new ConversationUpdated(
                    conversation: $conversation,
                    eventType: 'message.read',
                    eventId: (string) Str::uuid(),
                    occurredAt: $readAtValue->toIso8601String(),
                    userId: (int) $user->id,
                    changes: [
                        'last_read_message_id' => $lastReadMessageId,
                        'last_read_at' => $readAtValue->toIso8601String(),
                    ],
                ));

                event(new ConversationUnreadCountUpdated(
                    conversation: $conversation,
                    userId: (int) $user->id,
                    unreadCount: 0,
                    eventId: (string) Str::uuid(),
                    occurredAt: $readAtValue->toIso8601String(),
                ));
            });

            return $participant->fresh();
        });
    }

    public function unreadCount(User $user): int
    {
        return (int) ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->sum('unread_count');
    }

    public function typingStarted(Conversation $conversation, User $user): void
    {
        event(new ConversationTypingStarted(
            conversation: $conversation,
            userId: $user->id,
            eventId: (string) Str::uuid(),
            occurredAt: now()->toIso8601String(),
        ));
    }

    public function typingStopped(Conversation $conversation, User $user): void
    {
        event(new ConversationTypingStopped(
            conversation: $conversation,
            userId: $user->id,
            eventId: (string) Str::uuid(),
            occurredAt: now()->toIso8601String(),
        ));
    }

    public function conversationSummary(Conversation $conversation): array
    {
        $conversation->loadMissing(['participants.user', 'lastMessage.sender']);

        return [
            'id' => $conversation->id,
            'conversation_key' => $conversation->conversation_key,
            'context_type' => $conversation->context_type,
            'context_id' => $conversation->context_id,
            'is_group' => (bool) $conversation->is_group,
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            'participants' => $conversation->participants->map(function ($participant): array {
                return [
                    'user_id' => $participant->user_id,
                    'role' => $participant->role,
                    'user' => [
                        'id' => $participant->user?->id,
                        'name' => $participant->user?->name,
                        'username' => $participant->user?->username,
                        'avatar' => $participant->user?->avatar,
                    ],
                ];
            })->values()->all(),
        ];
    }

    private function buildConversationKey(int $actorId, array $participantIds, ?string $contextType, ?int $contextId): string
    {
        $ids = array_values(array_unique(array_map('intval', array_merge([$actorId], $participantIds))));
        sort($ids);

        return 'conversation:'.sha1(json_encode([
            'participants' => $ids,
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]));
    }

    private function ensureParticipant(Conversation $conversation, int $userId, bool $lock = false): ConversationParticipant
    {
        $query = ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $userId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $participant = $query->first();

        if (! $participant) {
            throw ValidationException::withMessages([
                'conversation' => 'You are not a participant in this conversation.',
            ]);
        }

        return $participant;
    }
}

