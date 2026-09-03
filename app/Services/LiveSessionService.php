<?php

namespace App\Services;

use App\Contracts\LiveStreamingProviderInterface;
use App\Enums\LiveStatus;
use App\Events\LiveUpdated;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveSessionService
{
    public function __construct(
        private readonly LiveAuthorizationService $authorization,
        private readonly LiveRecordingService $recordingService,
        private readonly LiveAnalyticsService $analyticsService,
        private readonly LiveReconciliationService $reconciliationService,
        private readonly LiveLikeService $likeService,
        private readonly LiveStreamingProviderInterface $provider,
    ) {
    }

    public function create(User $creator, array $data): LiveSession
    {
        $this->authorization->assertCreatorEligible($creator);

        $data = $this->normalizeCreateData($data);

        return DB::transaction(function () use ($creator, $data): LiveSession {
            $live = LiveSession::query()->create([
                'public_id' => (string) Str::uuid(),
                'creator_id' => $creator->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'cover_url' => $data['cover_url'],
                'visibility' => $data['visibility'],
                'scheduled_at' => $data['scheduled_at'],
                'provider' => config('live.provider', 'agora'),
                'provider_channel' => $this->provider->channelName(new LiveSession([
                    'public_id' => (string) Str::uuid(),
                ])),
                'status' => ! empty($data['scheduled_at']) ? LiveStatus::SCHEDULED : LiveStatus::CREATED,
                'chat_enabled' => $data['chat_enabled'],
                'gifts_enabled' => $data['gifts_enabled'],
                'recording_enabled' => $data['recording_enabled'],
                'notify_followers' => $data['notify_followers'],
                'age_restricted' => $data['age_restricted'],
                'stream_quality' => $data['stream_quality'],
                'orientation' => $data['orientation'],
                'moderation' => $data['moderation'],
            ]);

            if (($data['recording_enabled'] ?? false) && config('live.features.recording')) {
                $this->recordingService->request($live);
            }

            return $live->load('creator');
        });
    }

    private function normalizeCreateData(array $data): array
    {
        $moderation = is_array($data['moderation'] ?? null) ? $data['moderation'] : [];
        $blockedWords = array_values(array_unique(array_filter(array_map(
            static fn ($word) => is_string($word) ? trim($word) : '',
            $moderation['blocked_words'] ?? []
        ))));

        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => array_key_exists('description', $data)
                ? (is_string($data['description']) ? trim($data['description']) : $data['description'])
                : null,
            'category' => isset($data['category']) ? strtolower(trim((string) $data['category'])) : null,
            'cover_url' => $data['cover_url'] ?? null,
            'visibility' => isset($data['visibility']) ? strtolower(trim((string) $data['visibility'])) : 'public',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'chat_enabled' => array_key_exists('chat_enabled', $data) ? (bool) $data['chat_enabled'] : true,
            'gifts_enabled' => array_key_exists('gifts_enabled', $data) ? (bool) $data['gifts_enabled'] : true,
            'recording_enabled' => array_key_exists('recording_enabled', $data) ? (bool) $data['recording_enabled'] : false,
            'notify_followers' => array_key_exists('notify_followers', $data) ? (bool) $data['notify_followers'] : true,
            'age_restricted' => array_key_exists('age_restricted', $data) ? (bool) $data['age_restricted'] : false,
            'stream_quality' => isset($data['stream_quality']) ? strtolower(trim((string) $data['stream_quality'])) : '1080p_30fps',
            'orientation' => isset($data['orientation']) ? strtolower(trim((string) $data['orientation'])) : 'portrait',
            'moderation' => [
                'profanity_filter_enabled' => (bool) ($moderation['profanity_filter_enabled'] ?? false),
                'followers_only_chat' => (bool) ($moderation['followers_only_chat'] ?? false),
                'slow_mode_seconds' => $moderation['slow_mode_seconds'] ?? null,
                'blocked_words' => $blockedWords,
            ],
        ];
    }

    public function start(LiveSession $live, User $creator): array
    {
        abort_unless((int) $live->creator_id === (int) $creator->id, 403);
        $this->authorization->assertCreatorEligible($creator);

        return DB::transaction(function () use ($live, $creator): array {
            $live = LiveSession::query()->whereKey($live->id)->lockForUpdate()->firstOrFail();

            if (! $live->canTransitionTo(LiveStatus::STARTING)) {
                if ($live->status === LiveStatus::LIVE) {
                    return [$live->fresh('creator'), $this->provider->credentials($live, $creator, 'broadcaster')];
                }

                throw ValidationException::withMessages(['status' => 'Cannot start this Live session.']);
            }

            $live->forceFill([
                'status' => LiveStatus::STARTING,
                'started_at' => $live->started_at ?? now(),
            ])->save();

            $credentials = $this->provider->credentials($live, $creator, 'broadcaster');

            return [$live->fresh('creator'), $credentials];
        });
    }

    public function confirmLive(LiveSession $live): LiveSession
    {
        $live = $this->transition($live, LiveStatus::LIVE);

        if ($live->recording_enabled && config('live.features.recording')) {
            $this->recordingService->start($live);
        }

        return $live->fresh('creator');
    }

    public function reconnect(LiveSession $live): LiveSession
    {
        return $this->transition($live, LiveStatus::RECONNECTING);
    }

    public function end(LiveSession $live, string $reason = 'creator_ended'): LiveSession
    {
        return DB::transaction(function () use ($live, $reason): LiveSession {
            $live = LiveSession::query()->whereKey($live->id)->lockForUpdate()->firstOrFail();

            if ($live->status === LiveStatus::ENDED || $live->status === LiveStatus::TERMINATED) {
                return $live;
            }

            if ($live->canTransitionTo(LiveStatus::ENDING)) {
                $live->forceFill(['status' => LiveStatus::ENDING])->save();
            }

            if ($live->recording_enabled && config('live.features.recording')) {
                $this->recordingService->stop($live);
            }

            $this->provider->end($live);

            $live->forceFill([
                'ended_at' => now(),
                'termination_reason' => $reason,
                'status' => $reason === 'platform_terminated' ? LiveStatus::TERMINATED : LiveStatus::ENDED,
            ])->save();

            $live->viewerSessions()->whereNull('left_at')->each(function ($session): void {
                app(LivePresenceService::class)->leave($session);
            });

            $live->cohosts()->whereIn('status', ['active', 'accepted'])->update(['status' => 'removed', 'removed_at' => now()]);
            $live->cohostRequests()->whereIn('status', ['pending', 'accepted', 'active'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $this->likeService->flush($live);
            $this->analyticsService->upsertFromLive($live, ['termination_reason' => $reason]);

            LiveUpdated::dispatch($live->fresh('creator'), 'ended');

            return $live->fresh('creator');
        });
    }

    public function transition(LiveSession $live, LiveStatus $next): LiveSession
    {
        $current = $live->status instanceof LiveStatus ? $live->status : LiveStatus::from((string) $live->status);

        if (! $current->canTransitionTo($next)) {
            throw ValidationException::withMessages(['status' => "Cannot transition Live to {$next->value}."]);
        }

        $live->forceFill(['status' => $next])->save();

        return $live->fresh('creator');
    }

    public function reconcileStaleLive(LiveSession $live): bool
    {
        return $this->reconciliationService->reconcileStaleLive($live);
    }
}


