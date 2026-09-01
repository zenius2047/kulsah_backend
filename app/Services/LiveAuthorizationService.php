<?php

namespace App\Services;

use App\Enums\LiveCohostRequestStatus;
use App\Enums\LiveStatus;
use App\Models\LiveBattle;
use App\Models\LiveCohost;
use App\Models\LiveCohostRequest;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LiveAuthorizationService
{
    public function assertCreatorEligible(User $user): void
    {
        if (! $user->activated) {
            throw ValidationException::withMessages(['creator' => 'This account is not activated for Live.']);
        }

        if ($user->roles()->whereIn('name', ['creator', 'admin'])->doesntExist()) {
            throw ValidationException::withMessages(['creator' => 'This account is not eligible to host Live.']);
        }

        $hasActiveLive = LiveSession::query()
            ->where('creator_id', $user->id)
            ->active()
            ->exists();

        if ($hasActiveLive) {
            throw ValidationException::withMessages(['live' => 'The creator already has an active Live.']);
        }
    }

    public function assertViewerCanJoin(User $viewer, LiveSession $live): void
    {
        if (! in_array($live->status?->value ?? $live->status, [LiveStatus::STARTING->value, LiveStatus::LIVE->value, LiveStatus::RECONNECTING->value], true)) {
            throw ValidationException::withMessages(['live' => 'This Live is not active.']);
        }

        if ($viewer->isBlockedBy($live->creator) || $viewer->isBlocking($live->creator)) {
            throw ValidationException::withMessages(['live' => 'You cannot access this Live.']);
        }

        if ($this->isLiveBanned($viewer, $live)) {
            throw ValidationException::withMessages(['live' => 'You are banned from this Live.']);
        }

        if ($live->visibility === 'fans' && ! $viewer->isFanOf($live->creator)) {
            throw ValidationException::withMessages(['live' => 'This Live is for Fans only.']);
        }

        if ($live->visibility === 'subscribers' && ! $viewer->hasActiveSubscriptionTo($live->creator)) {
            throw ValidationException::withMessages(['live' => 'An active subscription is required.']);
        }
    }

    public function canPublish(User $user, LiveSession $live): bool
    {
        if ((int) $live->creator_id === (int) $user->id) {
            return true;
        }

        return LiveCohost::query()
            ->where('live_session_id', $live->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['accepted', 'active'])
            ->whereNull('removed_at')
            ->exists();
    }

    public function isModerator(User $user, LiveSession $live): bool
    {
        return (int) $live->creator_id === (int) $user->id
            || $live->moderators()->where('user_id', $user->id)->whereNull('removed_at')->exists();
    }

    public function isLiveBanned(User $user, LiveSession $live): bool
    {
        return $live->moderationActions()
            ->where('target_id', $user->id)
            ->where('action', 'ban_from_live')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function isMuted(User $user, LiveSession $live): bool
    {
        return $live->moderationActions()
            ->where('target_id', $user->id)
            ->where('action', 'mute')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function assertCanModerate(User $actor, LiveSession $live): void
    {
        if (! $this->isModerator($actor, $live) && ! $actor->roles()->whereIn('name', ['admin', 'moderator'])->exists()) {
            throw ValidationException::withMessages(['live' => 'You are not allowed to moderate this Live.']);
        }
    }

    public function assertBattleParticipants(LiveBattle $battle, User $user): void
    {
        if ((int) $battle->creator_id !== (int) $user->id && (int) $battle->opponent_id !== (int) $user->id) {
            throw ValidationException::withMessages(['battle' => 'You are not a participant in this Live Battle.']);
        }
    }

    public function assertCohostRequestActive(LiveCohostRequest $request, User $user): void
    {
        if ($request->invitee_id !== $user->id && $request->requester_id !== $user->id) {
            throw ValidationException::withMessages(['cohost' => 'You are not allowed to access this co-host request.']);
        }

        if (! in_array($request->status?->value ?? $request->status, [
            LiveCohostRequestStatus::PENDING->value,
            LiveCohostRequestStatus::ACCEPTED->value,
            LiveCohostRequestStatus::ACTIVE->value,
        ], true)) {
            throw ValidationException::withMessages(['cohost' => 'This co-host request is no longer active.']);
        }
    }
}

