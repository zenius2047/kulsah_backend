<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\LiveStreamingProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\IncomingVoiceCallNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceCallController extends Controller
{
    public function start(Conversation $conversation, Request $request, LiveStreamingProviderInterface $provider)
    {
        $caller = $request->user();
        $participantIds = $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id);
        abort_unless($participantIds->contains((int) $caller->id), 403);
        abort_if($conversation->is_group || $participantIds->count() !== 2, 422, 'Voice calls are currently available for direct conversations only.');
        $calleeId = $participantIds->first(fn (int $id) => $id !== (int) $caller->id);
        abort_unless($calleeId, 422, 'The call recipient is unavailable.');

        $call = DB::transaction(function () use ($conversation, $caller, $calleeId): VoiceCall {
            VoiceCall::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('status', ['ringing', 'connected'])
                ->lockForUpdate()
                ->update(['status' => 'ended', 'ended_at' => now()]);

            return VoiceCall::query()->create([
                'conversation_id' => $conversation->id,
                'caller_id' => $caller->id,
                'callee_id' => $calleeId,
                'channel_name' => 'kulsah-call-'.Str::lower(Str::random(32)),
                'status' => 'ringing',
            ]);
        });

        $callee = User::query()->findOrFail($calleeId);
        $callee->notify(new IncomingVoiceCallNotification($call, $caller));

        return response()->json([
            'data' => $this->resource($call->load(['caller', 'callee'])),
            'credentials' => $provider->voiceCredentials($call->channel_name, $caller),
        ], 201);
    }

    public function show(VoiceCall $voiceCall, Request $request)
    {
        $this->authorizeCall($voiceCall, $request);
        return response()->json(['data' => $this->resource($voiceCall->load(['caller', 'callee']))]);
    }

    public function accept(VoiceCall $voiceCall, Request $request, LiveStreamingProviderInterface $provider)
    {
        abort_unless((int) $voiceCall->callee_id === (int) $request->user()->id, 403);
        abort_unless($voiceCall->status === 'ringing', 409, 'This call is no longer ringing.');
        $voiceCall->update(['status' => 'connected', 'answered_at' => now()]);

        return response()->json([
            'data' => $this->resource($voiceCall->fresh(['caller', 'callee'])),
            'credentials' => $provider->voiceCredentials($voiceCall->channel_name, $request->user()),
        ]);
    }

    public function decline(VoiceCall $voiceCall, Request $request)
    {
        abort_unless((int) $voiceCall->callee_id === (int) $request->user()->id, 403);
        if ($voiceCall->status === 'ringing') $voiceCall->update(['status' => 'declined', 'ended_at' => now()]);
        return response()->json(['data' => $this->resource($voiceCall->fresh(['caller', 'callee']))]);
    }

    public function end(VoiceCall $voiceCall, Request $request)
    {
        $this->authorizeCall($voiceCall, $request);
        if (in_array($voiceCall->status, ['ringing', 'connected'], true)) $voiceCall->update(['status' => 'ended', 'ended_at' => now()]);
        return response()->json(['data' => $this->resource($voiceCall->fresh(['caller', 'callee']))]);
    }

    public function credentials(VoiceCall $voiceCall, Request $request, LiveStreamingProviderInterface $provider)
    {
        $this->authorizeCall($voiceCall, $request);
        abort_unless(in_array($voiceCall->status, ['ringing', 'connected'], true), 409, 'This call has ended.');
        return response()->json(['credentials' => $provider->voiceCredentials($voiceCall->channel_name, $request->user())]);
    }

    private function authorizeCall(VoiceCall $call, Request $request): void
    {
        abort_unless(in_array((int) $request->user()->id, [(int) $call->caller_id, (int) $call->callee_id], true), 403);
    }

    private function resource(VoiceCall $call): array
    {
        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'status' => $call->status,
            'caller' => ['id' => $call->caller_id, 'name' => $call->caller?->name, 'handle' => $call->caller?->username, 'avatar_url' => $call->caller?->avatar],
            'callee' => ['id' => $call->callee_id, 'name' => $call->callee?->name, 'handle' => $call->callee?->username, 'avatar_url' => $call->callee?->avatar],
            'answered_at' => optional($call->answered_at)?->toIso8601String(),
            'ended_at' => optional($call->ended_at)?->toIso8601String(),
            'created_at' => optional($call->created_at)?->toIso8601String(),
        ];
    }
}
