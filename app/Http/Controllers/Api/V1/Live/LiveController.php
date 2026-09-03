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
        return LiveSessionResource::collection($discovery->discover($request->user(), (int) $request->integer('per_page', 20)));
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

    public function confirm(LiveSession $liveSession, LiveSessionService $service)
    {
        $live = $service->confirmLive($liveSession);
        LiveUpdated::dispatch($live, 'status');
        LiveDirectoryUpdated::dispatch($live, 'status');

        return response()->json([
            'data' => new LiveSessionResource($live),
        ]);
    }

    public function reconnect(LiveSession $liveSession, LiveSessionService $service)
    {
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

    public function leave(LiveSession $liveSession, Request $request, LivePresenceService $presence)
    {
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

    public function declineCohost(LiveCohostRequest $cohostRequest, Request $request)
    {
        abort_unless(in_array($request->user()->id, [$cohostRequest->invitee_id, $cohostRequest->requester_id], true), 403);

        $cohostRequest->forceFill([
            'status' => 'declined',
            'responded_at' => now(),
            'declined_at' => now(),
        ])->save();

        return response()->json(['data' => $cohostRequest]);
    }

    public function removeCohost(LiveSession $liveSession, User $user, Request $request, LiveCohostService $cohosts)
    {
        $cohost = $cohosts->remove($liveSession, $request->user(), $user, $request->input('reason'));

        return response()->json(['data' => $cohost]);
    }

    public function inviteBattle(LiveSession $liveSession, Request $request, LiveBattleService $battles)
    {
        $validated = $request->validate([
            'opponent_live_session_public_id' => ['required', 'string', 'exists:live_sessions,public_id'],
        ]);

        $opponentLive = LiveSession::query()->where('public_id', $validated['opponent_live_session_public_id'])->firstOrFail();
        $battle = $battles->invite($liveSession, $opponentLive, $request->user());

        return response()->json(['data' => $battle], 201);
    }

    public function acceptBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        $battle = $battles->accept($battle, $request->user());

        return response()->json(['data' => $battle]);
    }

    public function scoreBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
        $battle = $battles->score($battle);

        return response()->json(['data' => $battle]);
    }

    public function endBattle(LiveBattle $battle, Request $request, LiveBattleService $battles)
    {
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
            $session = $liveSession->viewerSessions()->where('user_id', $target->id)->whereNull('left_at')->latest()->first();
            if ($session) {
                app(LivePresenceService::class)->leave($session);
            }
        }

        if ($validated['action'] === 'terminate_live') {
            $liveSession = app(LiveSessionService::class)->end($liveSession, 'platform_terminated');
        }

        LiveUpdated::dispatch($liveSession->fresh('creator'), 'moderation_applied');

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

    public function analytics(LiveSession $liveSession, LiveAnalyticsService $analytics)
    {
        $snapshot = $analytics->upsertFromLive($liveSession->fresh());

        return response()->json(['data' => $snapshot]);
    }
}






