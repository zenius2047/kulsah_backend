<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'archived' => ['sometimes'],
            'unread_only' => ['sometimes'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);

        $paginator = $this->conversationService->listConversations($request->user(), $validated, $page, $perPage);

        return response()->json([
            'data' => ConversationResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1', 'max:20'],
            'participant_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'context_type' => ['nullable', 'string', 'max:255'],
            'context_id' => ['nullable', 'integer'],
            'initial_message' => ['nullable', 'array'],
            'initial_message.client_message_id' => ['nullable', 'string', 'max:255'],
            'initial_message.type' => ['nullable', 'string', 'max:50'],
            'initial_message.body' => ['nullable', 'string'],
            'initial_message.attachment_ids' => ['nullable', 'array'],
            'initial_message.attachment_ids.*' => ['integer'],
            'initial_message.reply_to_message_id' => ['nullable', 'integer'],
            'initial_message.metadata' => ['nullable', 'array'],
            'initial_message.idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = $this->conversationService->createConversation($request->user(), $validated);

        return response()->json([
            'data' => new ConversationResource($conversation->fresh(['participants.user', 'lastMessage.sender', 'lastMessage.attachments', 'lastMessage.reactions'])),
        ], 201);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'before_message_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payload = $this->conversationService->getMessages(
            conversation: $conversation,
            user: $request->user(),
            beforeMessageId: isset($validated['before_message_id']) ? (int) $validated['before_message_id'] : null,
            perPage: (int) ($validated['per_page'] ?? 50),
        );

        return response()->json([
            'data' => ConversationMessageResource::collection($payload['messages'])->resolve($request),
            'meta' => [
                'has_more' => $payload['has_more'],
                'next_before_message_id' => $payload['next_before_message_id'],
            ],
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'client_message_id' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'body' => ['nullable', 'string'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer'],
            'reply_to_message_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $message = $this->conversationService->sendMessage($conversation, $request->user(), $validated);

        return response()->json([
            'data' => new ConversationMessageResource($message->fresh(['sender', 'attachments', 'replyTo.sender', 'reactions'])),
        ], 201);
    }

    public function read(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'last_read_message_id' => ['required', 'integer'],
            'read_at' => ['nullable', 'date'],
        ]);

        $participant = $this->conversationService->markConversationRead(
            conversation: $conversation,
            user: $request->user(),
            lastReadMessageId: (int) $validated['last_read_message_id'],
            readAt: $validated['read_at'] ?? null,
        );

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'last_read_message_id' => $participant->last_read_message_id,
                'last_read_at' => optional($participant->last_read_at)?->toIso8601String(),
                'unread_count' => (int) $participant->unread_count,
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'data' => [
                'unread_count' => $this->conversationService->unreadCount($request->user()),
            ],
        ]);
    }

    public function typingStart(Request $request, Conversation $conversation)
    {
        $this->conversationService->typingStarted($conversation, $request->user());

        return response()->json(['message' => 'Typing event sent.']);
    }

    public function typingStop(Request $request, Conversation $conversation)
    {
        $this->conversationService->typingStopped($conversation, $request->user());

        return response()->json(['message' => 'Typing event sent.']);
    }
}
