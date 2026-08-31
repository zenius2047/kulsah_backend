<?php

namespace App\Services;

use App\Enums\LiveCohostRequestStatus;
use App\Events\LiveUpdated;
use App\Models\LiveCohost;
use App\Models\LiveCohostRequest;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveCohostService
{
    public function __construct(
        private readonly LiveAuthorizationService $authorization,
        private readonly AgoraTokenService $tokens,
    ) {
    }

    public function request(LiveSession $live, User $requester, array $data = []): LiveCohostRequest
    {
        $this->authorization->assertViewerCanJoin($requester, $live);

        return LiveCohostRequest::query()->create([
            'live_session_id' => $live->id,
            'requester_id' => $requester->id,
            'invitee_id' => $live->creator_id,
            'requested_by_id' => $requester->id,
            'status' => LiveCohostRequestStatus::PENDING,
            'message' => $data['message'] ?? null,
            'expires_at' => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 5)),
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    public function invite(LiveSession $live, User $creator, User $invitee, array $data = []): LiveCohostRequest
    {
        $this->authorization->assertCanModerate($creator, $live);

        return LiveCohostRequest::query()->create([
            'live_session_id' => $live->id,
            'requester_id' => $invitee->id,
            'invitee_id' => $invitee->id,
            'requested_by_id' => $creator->id,
            'status' => LiveCohostRequestStatus::PENDING,
            'message' => $data['message'] ?? null,
            'expires_at' => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 5)),
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    public function accept(LiveCohostRequest $request, User $actor): array
    {
        $this->authorization->assertCohostRequestActive($request, $actor);

        return DB::transaction(function () use ($request, $actor): array {
            $live = $request->live()->lockForUpdate()->firstOrFail();

            if (! $live->status || $live->status->isTerminal()) {
                throw ValidationException::withMessages(['live' => 'This Live is no longer active.']);
            }

            $cohost = LiveCohost::query()->updateOrCreate(
                [
                    'live_session_id' => $live->id,
                    'user_id' => $actor->id,
                ],
                [
                    'status' => 'active',
                    'accepted_at' => now(),
                    'removed_at' => null,
                ]
            );

            $request->update([
                'status' => LiveCohostRequestStatus::ACTIVE,
                'responded_at' => now(),
                'accepted_at' => now(),
            ]);

            LiveUpdated::dispatch($live->fresh('creator'), 'cohost_accepted');

            return [
                'cohost' => $cohost,
                'credentials' => $this->tokens->generateCoHostToken($live, $actor),
            ];
        });
    }

    public function remove(LiveSession $live, User $actor, User $cohostUser, ?string $reason = null): LiveCohost
    {
        $this->authorization->assertCanModerate($actor, $live);

        $cohost = LiveCohost::query()->firstOrCreate(
            [
                'live_session_id' => $live->id,
                'user_id' => $cohostUser->id,
            ],
            [
                'status' => 'active',
                'accepted_at' => now(),
            ]
        );

        $cohost->forceFill([
            'status' => 'removed',
            'removed_at' => now(),
        ])->save();

        LiveCohostRequest::query()
            ->where('live_session_id', $live->id)
            ->where('invitee_id', $cohostUser->id)
            ->update([
                'status' => LiveCohostRequestStatus::REMOVED,
                'removed_at' => now(),
            ]);

        LiveUpdated::dispatch($live->fresh('creator'), 'cohost_removed');

        return $cohost;
    }
}


