<?php

namespace App\Services;

use App\Enums\LiveCohostRequestStatus as Status;
use App\Events\LiveUpdated;
use App\Models\LiveCohost;
use App\Models\LiveCohostRequest;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LiveCohostService
{
    public function __construct(private readonly LiveAuthorizationService $authorization, private readonly AgoraTokenService $tokens) {}

    public function request(LiveSession $live, User $requester, array $data = []): LiveCohostRequest
    {
        return $this->createRequest($live, $requester, $requester, $data);
    }

    public function invite(LiveSession $live, User $creator, User $invitee, array $data = []): LiveCohostRequest
    {
        abort_unless((int) $live->creator_id === (int) $creator->id, 403);
        return $this->createRequest($live, $invitee, $creator, $data);
    }

    private function createRequest(LiveSession $live, User $guest, User $sender, array $data): LiveCohostRequest
    {
        abort_unless(config('live.features.cohost', true), 422, 'Co-hosting is disabled.');
        return DB::transaction(function () use ($live, $guest, $sender, $data) {
            $live = LiveSession::query()->lockForUpdate()->findOrFail($live->id);
            $this->authorization->assertViewerCanJoin($guest, $live);
            abort_if((int) $guest->id === (int) $live->creator_id, 422, 'You already host this Live.');
            abort_if($this->authorization->canPublish($guest, $live), 422, 'Already a co-host.');
            $existing = $live->cohostRequests()->where('requester_id', $guest->id)
                ->whereIn('status', ['pending', 'accepted'])->where('expires_at', '>', now())->first();
            if ($existing) return $existing;
            $request = $live->cohostRequests()->updateOrCreate([
                'requester_id' => $guest->id,
                'invitee_id' => $sender->id === $guest->id ? $live->creator_id : $guest->id,
            ], [
                'requested_by_id' => $sender->id,
                'status' => Status::PENDING,
                'message' => $data['message'] ?? null,
                'expires_at' => now()->addMinutes(5),
                'responded_at' => null,
                'accepted_at' => null,
                'declined_at' => null,
                'cancelled_at' => null,
                'removed_at' => null,
            ]);
            DB::afterCommit(fn () => LiveUpdated::dispatch($live->fresh('creator'), 'cohost_requested'));
            return $request;
        });
    }

    public function accept(LiveCohostRequest $request, User $actor): array
    {
        abort_unless(config('live.features.cohost', true), 422, 'Co-hosting is disabled.');
        return DB::transaction(function () use ($request, $actor): array {
            $live = $request->live()->lockForUpdate()->firstOrFail();
            $request = LiveCohostRequest::query()->lockForUpdate()->findOrFail($request->id);
            $guest = $request->requester;
            $this->authorization->assertViewerCanJoin($guest, $live);
            $status = $request->status;
            abort_unless(in_array($status, [Status::PENDING, Status::ACCEPTED, Status::ACTIVE]), 422, 'Request is no longer active.');
            abort_if($status !== Status::ACTIVE && $request->expires_at?->isPast(), 422, 'Request has expired.');
            // Approval does not turn on a viewer's camera. They explicitly join after approval.
            if ($status === Status::PENDING && (int) $request->invitee_id === (int) $live->creator_id) {
                abort_unless((int) $actor->id === (int) $live->creator_id, 403);
                $request->update(['status' => Status::ACCEPTED, 'responded_at' => now()]);
                DB::afterCommit(fn () => LiveUpdated::dispatch($live->fresh('creator'), 'cohost_accepted'));
                return ['request' => $request->withoutRelations(), 'cohost' => null, 'credentials' => null];
            }
            abort_unless((int) $actor->id === (int) $guest->id, 403);
            if (! $this->authorization->canPublish($guest, $live)) {
                abort_if($live->cohosts()->where('status', 'active')->whereNull('removed_at')->count() >= (int) config('live.cohost_limit', 4), 422, 'The co-host stage is full.');
            }
            $metadata = $live->provider_metadata ?? [];
            $ban = $metadata['cohost_bans'][$guest->id] ?? null;
            if ($ban) {
                if ($ban['expires_at'] > now()->timestamp) $this->tokens->restorePublishing((int) $ban['id']);
                unset($metadata['cohost_bans'][$guest->id]);
                $live->update(['provider_metadata' => $metadata]);
            }
            $cohost = LiveCohost::query()->updateOrCreate(
                ['live_session_id' => $live->id, 'user_id' => $guest->id],
                ['status' => 'active', 'accepted_at' => now(), 'removed_at' => null]
            );
            $request->update(['status' => Status::ACTIVE, 'responded_at' => now(), 'accepted_at' => now()]);
            DB::afterCommit(fn () => LiveUpdated::dispatch($live->fresh('creator'), 'cohost_accepted'));
            return ['request' => $request->withoutRelations(), 'cohost' => $cohost, 'credentials' => $this->tokens->generateCoHostToken($live, $guest)];
        });
    }

    public function decline(LiveCohostRequest $request, User $actor): LiveCohostRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $live = $request->live()->lockForUpdate()->firstOrFail();
            $request = LiveCohostRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless(in_array((int) $actor->id, [(int) $request->requester_id, (int) $live->creator_id]), 403);
            abort_unless(in_array($request->status, [Status::PENDING, Status::ACCEPTED]), 422);
            $cancel = (int) $actor->id === (int) $request->requested_by_id;
            $request->update(['status' => $cancel ? Status::CANCELLED : Status::DECLINED, 'responded_at' => now()]);
            DB::afterCommit(fn () => LiveUpdated::dispatch($live->fresh('creator'), 'cohost_declined'));
            return $request;
        });
    }

    public function remove(LiveSession $live, User $actor, User $cohostUser, ?string $reason = null): LiveCohost
    {
        if ((int) $actor->id !== (int) $cohostUser->id) $this->authorization->assertCanModerate($actor, $live);
        return DB::transaction(function () use ($live, $cohostUser) {
            $live = LiveSession::query()->lockForUpdate()->findOrFail($live->id);
            $cohost = $live->cohosts()->where('user_id', $cohostUser->id)->firstOrFail();
            if ($cohost->status === 'active') {
                $ruleId = $this->tokens->revokePublishing($live, $cohostUser);
                $metadata = $live->provider_metadata ?? [];
                $metadata['cohost_bans'][$cohostUser->id] = [
                    'id' => $ruleId,
                    'expires_at' => now()->addSeconds((int) config('agora.publisher_token_ttl', 900) + 30)->timestamp,
                ];
                $live->update(['provider_metadata' => $metadata]);
            }
            $cohost->update(['status' => 'removed', 'removed_at' => now()]);
            $live->cohostRequests()->where('requester_id', $cohostUser->id)
                ->whereIn('status', ['pending', 'accepted', 'active'])
                ->update(['status' => Status::REMOVED->value, 'removed_at' => now()]);
            DB::afterCommit(fn () => LiveUpdated::dispatch($live->fresh('creator'), 'cohost_removed'));
            return $cohost;
        });
    }
}
