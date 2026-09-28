<?php

namespace App\Http\Controllers\Api\V1\Live;

use App\Events\LiveDirectoryUpdated;
use App\Events\LiveUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Live\StoreLiveRequest;
use App\Http\Resources\LiveSessionResource;
use App\Models\KulCoinGift;
use App\Models\LiveBattle;
use App\Models\LiveCohostRequest;
use App\Models\LiveSession;
use App\Models\SignalReport;
use App\Models\User;
use App\Services\AgoraTokenService;
use App\Services\LiveAnalyticsService;
use App\Services\LiveAuthorizationService;
use App\Services\LiveBattleService;
use App\Services\LiveCohostService;
use App\Services\LiveDiscoveryService;
use App\Services\LiveGiftService;
use App\Services\LiveLikeService;
use App\Services\LiveModerationService;
use App\Services\LivePresenceService;
use App\Services\LiveSessionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LiveController extends Controller
{
    public function index(Request $request, LiveDiscoveryService $discovery)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search_query' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return LiveSessionResource::collection($discovery->discover(
            viewer: $request->user(),
            perPage: (int) ($validated['per_page'] ?? 20),
            searchQuery: trim((string) ($validated['search_query'] ?? '')),
        ));
    }

    public function show(Request $request, LiveSession $liveSession)
    {
        $liveSession->loadMissing('creator');

        return response()->json([
            'data' => new LiveSessionResource($liveSession),
        ]);
    }

    public function store(StoreLiveRequest $request, LiveSessionService $service)
    {
        return response()->json([
            'data' => new LiveSessionResource($service->create($request->user(), $request->validated())),
        ], 201);
    }

    public function start(LiveSession $liveSession, Request $request, LiveSessionService $service)
    {
        [$live, $credentials] = $service->start($liveSession, $request->user());

        return response()->json([
            'data' => new LiveSessionResource($live),
            'credentials' => $credentials,
        ]);
    }

    public function confirm(LiveSession $liveSession, Request $request, LiveSessionService $service)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);
        $live = $service->confirmLive($liveSession);
        LiveUpdated::dispatch($live, 'status');
        LiveDirectoryUpdated::dispatch($live, 'status');

        return response()->json([
            'data' => new LiveSessionResource($live),
        ]);
    }

    public function reconnect(LiveSession $liveSession, Request $request, LiveSessionService $service)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);
        $live = $service->reconnect($liveSession);
        LiveUpdated::dispatch($live, 'status');
        LiveDirectoryUpdated::dispatch($live, 'status');

        return response()->json([
            'data' => new LiveSessionResource($live),
        ]);
    }

    public function heartbeat(LiveSession $liveSession, Request $request)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);

        $validated = $request->validate([
            'broadcast_state' => ['nullable', 'string', 'max:50'],
            'network_quality' => ['nullable', 'integer', 'min:0', 'max:5'],
            'bitrate' => ['nullable', 'integer', 'min:0'],
            'packet_loss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fps' => ['nullable', 'numeric', 'min:0'],
            'resolution' => ['nullable', 'string', 'max:50'],
            'audio_state' => ['nullable', 'string', 'max:50'],
            'video_state' => ['nullable', 'string', 'max:50'],
        ]);

        $liveSession->forceFill([
            'last_heartbeat_at' => now(),
            'heartbeat_payload' => $validated,
            'provider_metadata' => $liveSession->provider_metadata ?? [],
        ])->save();

        return response()->json(['message' => 'Heartbeat recorded.']);
    }

    public function end(LiveSession $liveSession, Request $request, LiveSessionService $service)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);

        $validated = $request->validate([
            'reason' => ['sometimes', Rule::in(['creator_ended', 'platform_terminated', 'provider_failure', 'network_timeout', 'system_timeout'])],
        ]);

        $live = $service->end($liveSession, $validated['reason'] ?? 'creator_ended');

        return response()->json([
            'data' => new LiveSessionResource($live),
        ]);
    }

    public function preview(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, AgoraTokenService $tokens)
    {
        $authorization->assertViewerCanJoin($request->user(), $liveSession);

        return response()->json([
            'data' => new LiveSessionResource($liveSession->fresh('creator')),
            'preview' => true,
            'credentials' => $tokens->generateAudienceToken($liveSession, $request->user()),
        ]);
    }
    public function join(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, LivePresenceService $presence, AgoraTokenService $tokens)
    {
        $authorization->assertViewerCanJoin($request->user(), $liveSession);

        [$session] = $presence->join($liveSession, $request->user());
        $credentials = $tokens->generateAudienceToken($liveSession, $request->user());

        return response()->json([
            'data' => new LiveSessionResource($liveSession->fresh('creator')),
            'watch_session_id' => $session->id,
            'credentials' => $credentials,
        ]);
    }

    public function leave(LiveSession $liveSession, Request $request, LivePresenceService $presence, LiveCohostService $cohosts)
    {
        if ($liveSession->cohosts()->where('user_id', $request->user()->id)->where('status', 'active')->exists()) {
            $cohosts->remove($liveSession, $request->user(), $request->user());
        }
        $liveSession->cohostRequests()->where('requester_id', $request->user()->id)
            ->whereIn('status', ['pending', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $session = $liveSession->viewerSessions()
            ->where('user_id', $request->user()->id)
            ->whereNull('left_at')
            ->latest()
            ->first();

        if ($session) {
            $presence->leave($session);
        }

        return response()->json(['message' => 'You left the Live.']);
    }

    public function comment(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, LiveModerationService $moderation)
    {
        if (! $liveSession->chat_enabled) {
            throw ValidationException::withMessages(['live' => 'Chat is disabled.']);
        }

        $authorization->assertViewerCanJoin($request->user(), $liveSession);

        if ($authorization->isMuted($request->user(), $liveSession)) {
            throw ValidationException::withMessages(['live' => 'You are muted in this Live.']);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        $comment = $liveSession->comments()->create([
            'user_id' => $request->user()->id,
            'body' => trim($validated['body']),
        ]);

        $liveSession->forceFill([
            'comments_count' => (int) $liveSession->comments_count + 1,
        ])->saveQuietly();

        LiveUpdated::dispatch($liveSession->fresh('creator'), 'chat_created', [
            'comment' => [
                'id' => $comment->id,
                'user_id' => $comment->user_id,
                'body' => $comment->body,
                'user' => [
                    'id' => $comment->user?->id,
                    'name' => $comment->user?->name,
                    'username' => $comment->user?->username,
                    'avatar' => $comment->user?->avatar,
                ],
                'created_at' => optional($comment->created_at)?->toIso8601String(),
            ],
        ]);

        return response()->json([
            'data' => $comment->load('user'),
        ], 201);
    }

    public function like(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, LiveLikeService $likes)
    {
        $authorization->assertViewerCanJoin($request->user(), $liveSession);

        $validated = $request->validate([
            'count' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'data' => $likes->like($liveSession, $request->user(), (int) ($validated['count'] ?? 1)),
        ]);
    }

    public function gift(LiveSession $liveSession, Request $request, LiveGiftService $gifts)
    {
        $validated = $request->validate([
            'gift_id' => ['required', 'integer', 'exists:kulcoin_gifts,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $gift = KulCoinGift::query()->findOrFail($validated['gift_id']);

        $transaction = $gifts->send(
            live: $liveSession,
            sender: $request->user(),
            gift: $gift,
            quantity: (int) ($validated['quantity'] ?? 1),
            data: [
                'idempotency_key' => $validated['idempotency_key'],
                'message' => $validated['message'] ?? null,
                'ip_address' => $request->ip(),
            ]
        );

        return response()->json([
            'data' => $transaction,
        ], 201);
    }

    public function requestCohost(LiveSession $liveSession, Request $request, LiveCohostService $cohosts)
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $cohostRequest = $cohosts->request($liveSession, $request->user(), $validated);

        return response()->json(['data' => $cohostRequest], 201);
    }

    public function inviteCohost(LiveSession $liveSession, Request $request, LiveCohostService $cohosts)
    {
        $validated = $request->validate([
            'invitee_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $invitee = User::query()->findOrFail($validated['invitee_id']);
        $cohostRequest = $cohosts->invite($liveSession, $request->user(), $invitee, $validated);

        return response()->json(['data' => $cohostRequest], 201);
    }

    public function acceptCohost(LiveCohostRequest $cohostRequest, Request $request, LiveCohostService $cohosts)
    {
        $result = $cohosts->accept($cohostRequest, $request->user());

        return response()->json(['data' => $result]);
    }

    public function declineCohost(LiveCohostRequest $cohostRequest, Request $request, LiveCohostService $cohosts)
    {
        return response()->json(['data' => $cohosts->decline($cohostRequest, $request->user())]);
    }

    public function participants(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization)
    {
        $authorization->assertViewerCanJoin($request->user(), $liveSession);
        $creator = (int) $liveSession->creator_id === (int) $request->user()->id;
        $stageBattles = LiveBattle::query()->where('creator_live_session_id', $liveSession->id)
            ->whereIn('status', ['pending', 'accepted', 'active'])->get()
            ->filter(fn ($battle) => (bool) ($battle->metadata['shared_stage'] ?? false))
            ->filter(fn ($battle) => $creator
                || (int) $battle->opponent_id === (int) $request->user()->id
                || $battle->status->value === 'active');
        $battleStage = null;
        if ($stageBattles->isNotEmpty()) {
            $stageIds = $stageBattles->pluck('opponent_id')->prepend($liveSession->creator_id)->unique()->values();
            $stageUsers = User::query()->whereIn('id', $stageIds)->get()->keyBy('id');
            $identities = \App\Models\LiveProviderIdentity::query()->where('provider', 'agora')
                ->whereIn('user_id', $stageIds)->pluck('provider_uid', 'user_id');
            $battleStage = [
                'all_accepted' => $stageBattles->every(fn ($battle) => in_array($battle->status->value, ['accepted', 'active'])),
                'participants' => $stageIds->map(fn ($id) => [
                    'user_id' => (int) $id, 'rtc_uid' => (int) ($identities[$id] ?? $id),
                    'name' => $stageUsers[$id]?->name ?? 'Creator',
                    'username' => $stageUsers[$id]?->username,
                    'avatar' => $stageUsers[$id]?->avatar,
                    'verified' => (bool) ($stageUsers[$id]?->verified ?? false),
                    'accepted' => (int) $id === (int) $liveSession->creator_id
                        || $stageBattles->contains(fn ($battle) => (int) $battle->opponent_id === (int) $id && in_array($battle->status->value, ['accepted', 'active'])),
                ])->all(),
            ];
        }
        $requests = $liveSession->cohostRequests()
            ->when(! $creator, fn ($q) => $q->where('requester_id', $request->user()->id))
            ->with('requester:id,name,username,avatar')->latest('updated_at')->limit(100)->get();
        $requests->each(function ($item) {
            $item->requester?->setAppends([]);
            if (in_array($item->status->value, ['pending', 'accepted']) && $item->expires_at?->isPast()) {
                $item->status = 'expired';
            }
        });
        return response()->json(['data' => [
            'requests' => $requests,
            'battle_stage' => $battleStage,
            'vote_price_kc' => max(1, (int) config('kulcoin.vote_coin_price', 10)),
            'cohosts' => $liveSession->cohosts()->where('status', 'active')->whereNull('removed_at')->with('user:id,name,username,avatar')->get()->each(fn ($cohost) => $cohost->user?->setAppends([])),
            'viewers' => $creator ? User::query()->whereIn('id', $liveSession->viewerSessions()->whereNull('left_at')->select('user_id'))
                ->where('id', '!=', $liveSession->creator_id)->select('id', 'name', 'username', 'avatar')->limit(100)->get()->each(fn ($user) => $user->setAppends([])) : [],
            'battles' => LiveBattle::query()->where(fn ($q) => $q->where('creator_live_session_id', $liveSession->id)->orWhere('opponent_live_session_id', $liveSession->id))
                ->when(! $creator, fn ($q) => $q->where(fn ($q) => $q->where('status', 'active')->orWhere('opponent_id', $request->user()->id)))
                ->with(['creator:id,name,username,avatar', 'opponent:id,name,username,avatar'])
                ->latest()->limit(20)->get(),
        ]]);
    }

    public function cohostCredentials(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, AgoraTokenService $tokens)
    {
        $authorization->assertViewerCanJoin($request->user(), $liveSession);
        abort_unless($authorization->canPublish($request->user(), $liveSession), 403);
        return response()->json(['data' => $tokens->generateCoHostToken($liveSession, $request->user())]);
    }

    public function leaveCohost(LiveSession $liveSession, Request $request, LiveCohostService $cohosts)
    {
        return response()->json(['data' => $cohosts->remove($liveSession, $request->user(), $request->user())]);
    }

    public function removeCohost(LiveSession $liveSession, User $user, Request $request, LiveCohostService $cohosts)
    {
        $cohost = $cohosts->remove($liveSession, $request->user(), $user, $request->input('reason'));

        return response()->json(['data' => $cohost]);
    }

    public function inviteBattle(LiveSession $liveSession, Request $request, LiveBattleService $battles)
    {
        $validated = $request->validate([
            'opponent_id' => ['required_without:opponent_live_session_public_id', 'integer', 'exists:users,id'],
            'opponent_live_session_public_id' => ['required_without:opponent_id', 'string', 'exists:live_sessions,public_id'],
        ]);

        $opponent = isset($validated['opponent_id']) ? User::query()->findOrFail($validated['opponent_id'])
            : LiveSession::query()->where('public_id', $validated['opponent_live_session_public_id'])->firstOrFail();
        $battle = $battles->invite($liveSession, $opponent, $request->user());

        return response()->json(['data' => $battle], 201);
    }

    public function battleCreators(LiveSession $liveSession, Request $request, \App\Services\RealtimePresenceService $presence)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);
        $validated = $request->validate([
            'search_query' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $limit = (int) ($validated['limit'] ?? 30);
        $words = array_values(array_filter(array_map(
            fn ($word) => ltrim($word, '@'),
            preg_split('/\s+/u', trim($validated['search_query'] ?? '')) ?: []
        ), fn ($word) => $word !== ''));
        $query = User::query()->select('id', 'name', 'username', 'avatar')
            ->where('id', '!=', $request->user()->id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'creator'));
        foreach ($words as $word) {
            $query->where(fn ($query) => $query->whereLike('name', '%'.$word.'%')->orWhereLike('username', '%'.$word.'%'));
        }
        // Apply online status before pagination, independently of discovery/view history
        // and the requesting device's websocket presence snapshot.
        $creators = $query->lazyById(100)
            ->filter(fn (User $user) => $presence->isOnline($user))
            ->skip(($page - 1) * $limit)->take($limit + 1)->values()->collect();
        return response()->json([
            'data' => $creators->take($limit)->map(fn (User $user) => [
                'id' => $user->id, 'name' => $user->name, 'handle' => $user->username,
                'avatar_url' => $user->avatar, 'is_online' => true,
            ])->values(),
            'meta' => ['current_page' => $page, 'has_more' => $creators->count() > $limit],
        ]);
    }

    public function acceptBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        $battle = $battles->accept($battle, $request->user());

        return response()->json(['data' => $battle, 'credentials' => ($battle->metadata['shared_stage'] ?? false)
            ? app(AgoraTokenService::class)->generateCoHostToken($battle->creatorLive, $request->user()) : null]);
    }

    public function voteBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'vote_count' => ['required', 'integer', 'min:1', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $result = $battles->vote($battle, $request->user(), $validated);

        return response()->json(['data' => [
            'battle' => $result['battle'],
            'transaction_id' => $result['transaction']->id,
            'coin_amount' => (int) $result['transaction']->coin_amount,
            'vote_count' => (int) ($result['transaction']->metadata['vote_count'] ?? $validated['vote_count']),
            'target_user_id' => (int) $validated['target_user_id'],
        ]]);
    }

    public function scoreBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        app(LiveAuthorizationService::class)->assertBattleParticipants($battle, $request->user());
        $battle = $battles->score($battle);

        return response()->json(['data' => $battle]);
    }

    public function endBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        app(LiveAuthorizationService::class)->assertBattleParticipants($battle, $request->user());
        $battle = $battles->end($battle);

        return response()->json(['data' => $battle]);
    }

    public function moderate(LiveSession $liveSession, Request $request, LiveAuthorizationService $authorization, LiveModerationService $moderation)
    {
        $authorization->assertCanModerate($request->user(), $liveSession);

        $validated = $request->validate([
            'target_id' => ['required', 'integer', 'exists:users,id'],
            'action' => ['required', Rule::in(['mute', 'unmute', 'remove', 'ban_from_live', 'unban_from_live', 'terminate_live'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ]);

        $target = User::query()->findOrFail($validated['target_id']);
        abort_if((int) $target->id === (int) $liveSession->creator_id && $validated['action'] !== 'terminate_live', 422, 'The host cannot be moderated as a viewer.');
        $expiresAt = isset($validated['duration_seconds']) ? now()->addSeconds((int) $validated['duration_seconds']) : null;

        $action = $moderation->record(
            live: $liveSession,
            actor: $request->user(),
            target: $target,
            action: $validated['action'],
            reason: $validated['reason'] ?? null,
            durationSeconds: $validated['duration_seconds'] ?? null,
            expiresAt: $expiresAt
        );

        if (in_array($validated['action'], ['remove', 'ban_from_live'], true)) {
            if ($liveSession->cohosts()->where('user_id', $target->id)->where('status', 'active')->exists()) {
                app(LiveCohostService::class)->remove($liveSession, $request->user(), $target);
            }
            $session = $liveSession->viewerSessions()->where('user_id', $target->id)->whereNull('left_at')->latest()->first();
            if ($session) {
                app(LivePresenceService::class)->leave($session);
            }
        }

        if ($validated['action'] === 'terminate_live') {
            $liveSession = app(LiveSessionService::class)->end($liveSession, 'platform_terminated');
        }

        LiveUpdated::dispatch($liveSession->fresh('creator'), 'moderation_applied', [
            'moderation' => ['target_id' => $target->id, 'action' => $validated['action']],
        ]);

        return response()->json(['data' => $action]);
    }

    public function report(LiveSession $liveSession, Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $report = SignalReport::query()->create([
            'reporter_id' => $request->user()->id,
            'reportable_type' => LiveSession::class,
            'reportable_id' => $liveSession->id,
            'category' => $validated['category'],
            'reason' => $validated['reason'] ?? null,
            'status' => 'open',
        ]);

        return response()->json(['data' => $report], 201);
    }

    public function analytics(LiveSession $liveSession, Request $request, LiveAnalyticsService $analytics)
    {
        abort_unless((int) $liveSession->creator_id === (int) $request->user()->id, 403);
        $snapshot = $analytics->upsertFromLive($liveSession->fresh());

        return response()->json(['data' => $snapshot]);
    }
}
