<?php

namespace App\Services;

use App\Enums\ConversationMessageRequestStatus;
use App\Events\ConversationMessageRequestAccepted;
use App\Events\ConversationMessageRequestCreated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageRequest;
use App\Models\SignalReport;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserFollow;
use App\Notifications\ConversationMessageRequestAcceptedNotification;
use App\Notifications\ConversationMessageRequestNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignalMessagingService
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {
    }

    public function resolve(User $sender, User $receiver): array
    {
        if ((int) $sender->id === (int) $receiver->id) {
            return ['state' => 'blocked', 'reason' => 'You cannot message yourself.'];
        }

        if ($this->isBlocked($sender, $receiver)) {
            return ['state' => 'blocked', 'reason' => 'Messaging is blocked.'];
        }

        if ($this->findDirectConversation($sender, $receiver)) {
            return ['state' => 'direct_message_allowed'];
        }

        if ($sender->areMutualFans($receiver)) {
            return ['state' => 'direct_message_allowed'];
        }

        if ($sender->hasActiveSubscriptionTo($receiver)) {
            return ['state' => 'direct_message_allowed'];
        }

        if ($this->receiverAllowsDirectMessage($sender, $receiver)) {
            return ['state' => 'direct_message_allowed'];
        }

        $request = $this->latestRequestBetween($sender, $receiver);
        if ($request?->status === ConversationMessageRequestStatus::Pending->value) {
            return ['state' => 'request_already_pending', 'request' => $request];
        }

        if ($request?->status === ConversationMessageRequestStatus::Declined->value && $request->cooldown_until && $request->cooldown_until->isFuture()) {
            return ['state' => 'request_cooldown', 'request' => $request];
        }

        return ['state' => 'request_required'];
    }

    public function startConversationOrRequest(User $sender, User $receiver, array $data = []): array
    {
        $resolution = $this->resolve($sender, $receiver);

        if ($resolution['state'] === 'blocked') {
            throw ValidationException::withMessages([
                'receiver_id' => $resolution['reason'] ?? 'Messaging is blocked.',
            ]);
        }

        if ($resolution['state'] === 'direct_message_allowed') {
            $conversation = $this->findDirectConversation($sender, $receiver);

            if ($conversation) {
                return [
                    'decision' => 'direct',
                    'conversation' => $conversation,
                    'request' => null,
                ];
            }

            return [
                'decision' => 'direct',
                'conversation' => $this->conversationService->createConversation($sender, [
                    'participant_ids' => [$receiver->id],
                    'initial_message' => Arr::get($data, 'initial_message'),
                ]),
                'request' => null,
            ];
        }

        if ($resolution['state'] === 'request_already_pending') {
            throw ValidationException::withMessages([
                'receiver_id' => 'A message request is already pending for this user.',
            ]);
        }

        if ($resolution['state'] === 'request_cooldown') {
            throw ValidationException::withMessages([
                'receiver_id' => 'You must wait before sending another message request.',
            ]);
        }

        $initialMessage = Arr::get($data, 'initial_message', []);
        $body = trim((string) Arr::get($initialMessage, 'body', ''));

        if ($body === '') {
            throw ValidationException::withMessages([
                'initial_message.body' => 'An introductory message is required when a request is needed.',
            ]);
        }

        $request = ConversationMessageRequest::query()->updateOrCreate(
            [
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
            ],
            [
                'status' => ConversationMessageRequestStatus::Pending->value,
                'conversation_id' => null,
                'intro_client_message_id' => $initialMessage['client_message_id'] ?? (string) Str::uuid(),
                'intro_type' => $initialMessage['type'] ?? 'text',
                'intro_body' => $body,
                'intro_metadata' => $initialMessage['metadata'] ?? [],
                'idempotency_key' => $initialMessage['idempotency_key'] ?? null,
                'accepted_at' => null,
                'declined_at' => null,
                'blocked_at' => null,
                'cancelled_at' => null,
                'cooldown_until' => null,
            ]
        );

        $request->load(['sender', 'receiver']);

        Notification::sendNow($receiver, new ConversationMessageRequestNotification($request));
        event(new ConversationMessageRequestCreated($request));

        return [
            'decision' => 'request',
            'conversation' => null,
            'request' => $request,
        ];
    }

    public function incomingRequests(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return ConversationMessageRequest::query()
            ->with(['sender', 'receiver'])
            ->where('receiver_id', $user->id)
            ->where('status', ConversationMessageRequestStatus::Pending->value)
            ->latest()
            ->paginate($perPage);
    }

    public function acceptRequest(User $actor, ConversationMessageRequest $requestModel): Conversation
    {
        $this->assertReceiver($actor, $requestModel);

        return DB::transaction(function () use ($requestModel, $actor): Conversation {
            $requestModel->refresh();

            if ($requestModel->status !== ConversationMessageRequestStatus::Pending->value) {
                throw ValidationException::withMessages([
                    'request' => 'This request is no longer pending.',
                ]);
            }

            $conversation = $this->findDirectConversation($actor, $requestModel->sender)
                ?? $this->conversationService->createConversation($actor, [
                    'participant_ids' => [$requestModel->sender_id],
                    'initial_message' => $requestModel->intro_body ? [
                        'client_message_id' => $requestModel->intro_client_message_id,
                        'type' => $requestModel->intro_type,
                        'body' => $requestModel->intro_body,
                        'metadata' => $requestModel->intro_metadata ?? [],
                        'idempotency_key' => $requestModel->idempotency_key,
                    ] : null,
                ]);

            $requestModel->update([
                'status' => ConversationMessageRequestStatus::Accepted->value,
                'conversation_id' => $conversation->id,
                'accepted_at' => now(),
            ]);

            $requestModel->load(['sender', 'receiver', 'conversation.participants.user', 'conversation.lastMessage.sender']);
            Notification::sendNow($requestModel->sender, new ConversationMessageRequestAcceptedNotification($requestModel));
            event(new ConversationMessageRequestAccepted($requestModel));

            return $conversation;
        });
    }

    public function declineRequest(User $actor, ConversationMessageRequest $requestModel): ConversationMessageRequest
    {
        $this->assertReceiver($actor, $requestModel);

        $requestModel->update([
            'status' => ConversationMessageRequestStatus::Declined->value,
            'declined_at' => now(),
            'cooldown_until' => now()->addDays(7),
        ]);

        return $requestModel->fresh(['sender', 'receiver']);
    }

    public function blockRequest(User $actor, ConversationMessageRequest $requestModel, ?string $reason = null): ConversationMessageRequest
    {
        $this->assertReceiver($actor, $requestModel);

        DB::transaction(function () use ($actor, $requestModel, $reason): void {
            UserBlock::query()->updateOrCreate([
                'blocker_id' => $actor->id,
                'blocked_id' => $requestModel->sender_id,
            ], [
                'reason' => $reason,
            ]);

            $requestModel->update([
                'status' => ConversationMessageRequestStatus::Blocked->value,
                'blocked_at' => now(),
            ]);
        });

        return $requestModel->fresh(['sender', 'receiver']);
    }

    public function cancelRequest(User $actor, ConversationMessageRequest $requestModel): ConversationMessageRequest
    {
        if ((int) $actor->id !== (int) $requestModel->sender_id) {
            throw ValidationException::withMessages([
                'request' => 'You cannot cancel this request.',
            ]);
        }

        $requestModel->update([
            'status' => ConversationMessageRequestStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);

        return $requestModel->fresh(['sender', 'receiver']);
    }

    public function ensureSubscriptionPromotesFan(User $subscriber, User $creator): void
    {
        if ((int) $subscriber->id === (int) $creator->id) {
            return;
        }

        UserFollow::query()->firstOrCreate([
            'follower_id' => $subscriber->id,
            'followed_id' => $creator->id,
        ]);
    }

    public function applySubscriptionBlock(User $creator, User $subscriber, ?string $reason = null): void
    {
        UserBlock::query()->updateOrCreate([
            'blocker_id' => $creator->id,
            'blocked_id' => $subscriber->id,
        ], [
            'reason' => $reason,
        ]);
    }

    public function findDirectConversation(User $a, User $b): ?Conversation
    {
        return Conversation::query()
            ->where('is_group', false)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $a->id))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $b->id))
            ->whereDoesntHave('participants', fn ($query) => $query->whereNotIn('user_id', [$a->id, $b->id]))
            ->first();
    }

    public function searchUsers(User $viewer, string $searchQuery, int $limit = 20): array
    {
        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return [];
        }

        $blockedIds = UserBlock::query()
            ->where('blocker_id', $viewer->id)
            ->pluck('blocked_id')
            ->merge(
                UserBlock::query()
                    ->where('blocked_id', $viewer->id)
                    ->pluck('blocker_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $query = User::query()
            ->with(['roles', 'notificationPreference'])
            ->where('id', '!=', $viewer->id)
            ->where('activated', true)
            ->whereNotIn('id', $blockedIds->all())
            ->where(function ($discoverabilityQuery): void {
                $discoverabilityQuery->whereDoesntHave('notificationPreference')
                    ->orWhereHas('notificationPreference', function ($preferences): void {
                        $preferences->where('signal_discoverable_by_search', true);
                    });
            })
            ->where(function ($search) use ($searchQuery): void {
                $exact = strtolower($searchQuery);
                $like = '%'.$searchQuery.'%';
                $search->whereRaw('lower(username) = ?', [$exact])
                    ->orWhereRaw('lower(name) = ?', [$exact])
                    ->orWhereLike('username', $like)
                    ->orWhereLike('name', $like);
            })
            ->orderByRaw('case when lower(username) = ? then 0 when lower(name) = ? then 1 when lower(username) like ? then 2 when lower(name) like ? then 3 else 4 end', [
                strtolower($searchQuery),
                strtolower($searchQuery),
                '%'.$searchQuery.'%',
                '%'.$searchQuery.'%',
            ])
            ->orderBy('username')
            ->limit($limit);

        return $query->get()->all();
    }

    public function report(User $actor, string $reportableType, int $reportableId, ?string $reason = null): SignalReport
    {
        $map = [
            'user' => User::class,
            'message' => ConversationMessage::class,
            'request' => ConversationMessageRequest::class,
            'conversation' => Conversation::class,
        ];

        abort_unless(isset($map[$reportableType]), 422, 'Unsupported report target.');

        $modelClass = $map[$reportableType];
        $reportable = $modelClass::query()->findOrFail($reportableId);

        return SignalReport::query()->create([
            'reporter_id' => $actor->id,
            'reportable_type' => $modelClass,
            'reportable_id' => $reportable->id,
            'category' => $reportableType,
            'reason' => $reason,
            'status' => 'open',
        ]);
    }

    private function receiverAllowsDirectMessage(User $sender, User $receiver): bool
    {
        $preferences = $receiver->notificationPreference;
        $policy = (string) ($preferences?->signal_message_policy ?? 'people_i_may_know');

        return match ($policy) {
            'everyone' => true,
            'no_one' => false,
            'fans' => $sender->isFanOf($receiver),
            'mutual_fans' => $sender->areMutualFans($receiver),
            'people_i_may_know' => false,
            default => false,
        };
    }

    private function latestRequestBetween(User $sender, User $receiver): ?ConversationMessageRequest
    {
        return ConversationMessageRequest::query()
            ->where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->first();
    }

    private function isBlocked(User $sender, User $receiver): bool
    {
        return UserBlock::query()->where('blocker_id', $sender->id)->where('blocked_id', $receiver->id)->exists()
            || UserBlock::query()->where('blocker_id', $receiver->id)->where('blocked_id', $sender->id)->exists()
            || Subscription::query()->where('subscriber_id', $sender->id)->where('creator_id', $receiver->id)->where('status', 'blocked')->exists()
            || Subscription::query()->where('subscriber_id', $receiver->id)->where('creator_id', $sender->id)->where('status', 'blocked')->exists();
    }

    private function assertReceiver(User $actor, ConversationMessageRequest $requestModel): void
    {
        if ((int) $actor->id !== (int) $requestModel->receiver_id) {
            throw ValidationException::withMessages([
                'request' => 'You are not allowed to modify this message request.',
            ]);
        }
    }
}


