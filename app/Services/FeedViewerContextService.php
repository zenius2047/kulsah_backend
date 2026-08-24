<?php

namespace App\Services;

use App\Models\FeedViewerState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class FeedViewerContextService
{
    public function resolve(Request $request): array
    {
        $user = $request->user();
        $deviceKey = $this->resolveDeviceKey($request);

        if ($user) {
            $viewerKey = 'user:'.$user->id;
            $state = $this->mergeGuestStateIntoUserState($viewerKey, (int) $user->id, $deviceKey);
        } else {
            $viewerKey = 'guest:'.$deviceKey;
            $state = FeedViewerState::query()->firstOrCreate(
                ['viewer_key' => $viewerKey],
                [
                    'device_key' => $deviceKey,
                    'seen_video_ids' => [],
                    'interest_terms' => [],
                    'creator_affinity' => [],
                    'last_seen_at' => now(),
                ]
            );
        }

        return [
            'viewer_key' => $viewerKey,
            'viewer_state' => $state,
            'viewer_state_updated_at' => optional($state->updated_at)?->toISOString(),
            'device_key' => $deviceKey,
            'user_id' => $user ? (int) $user->id : null,
            'is_guest' => ! (bool) $user,
            'seen_video_ids' => $this->normalizeIds($state->seen_video_ids ?? []),
            'interest_terms' => $this->normalizeTerms($state->interest_terms ?? []),
            'creator_affinity' => is_array($state->creator_affinity ?? null) ? $state->creator_affinity : [],
        ];
    }

    /**
     * @param  array<int, int|string>  $videoIds
     */
    public function recordServedVideos(string $viewerKey, array $videoIds): ?FeedViewerState
    {
        $state = FeedViewerState::query()->where('viewer_key', $viewerKey)->first();

        if (! $state) {
            return null;
        }

        $seen = array_values(array_unique(array_merge(
            $this->normalizeIds($state->seen_video_ids ?? []),
            $this->normalizeIds($videoIds)
        )));

        $state->forceFill([
            'seen_video_ids' => array_slice($seen, -500),
            'last_seen_at' => now(),
        ])->save();

        return $state->fresh();
    }

    private function mergeGuestStateIntoUserState(string $viewerKey, int $userId, string $deviceKey): FeedViewerState
    {
        $userState = FeedViewerState::query()->firstOrNew(['viewer_key' => $viewerKey]);
        $guestState = FeedViewerState::query()
            ->where('device_key', $deviceKey)
            ->whereNull('user_id')
            ->first();

        if ($guestState) {
            $userState->forceFill([
                'viewer_key' => $viewerKey,
                'user_id' => $userId,
                'device_key' => $deviceKey,
                'seen_video_ids' => array_values(array_unique(array_merge(
                    $this->normalizeIds($guestState->seen_video_ids ?? []),
                    $this->normalizeIds($userState->seen_video_ids ?? [])
                ))),
                'interest_terms' => array_values(array_unique(array_merge(
                    $this->normalizeTerms($guestState->interest_terms ?? []),
                    $this->normalizeTerms($userState->interest_terms ?? [])
                ))),
                'creator_affinity' => array_merge(
                    is_array($guestState->creator_affinity ?? null) ? $guestState->creator_affinity : [],
                    is_array($userState->creator_affinity ?? null) ? $userState->creator_affinity : []
                ),
                'last_seen_at' => $guestState->last_seen_at ?? now(),
            ]);

            $guestState->delete();
        } else {
            $userState->forceFill([
                'viewer_key' => $viewerKey,
                'user_id' => $userId,
                'device_key' => $deviceKey,
                'seen_video_ids' => $this->normalizeIds($userState->seen_video_ids ?? []),
                'interest_terms' => $this->normalizeTerms($userState->interest_terms ?? []),
                'creator_affinity' => is_array($userState->creator_affinity ?? null) ? $userState->creator_affinity : [],
                'last_seen_at' => $userState->last_seen_at ?? now(),
            ]);
        }

        $userState->save();

        return $userState->fresh();
    }

    private function resolveDeviceKey(Request $request): string
    {
        foreach (['X-Kulsah-Device-Id', 'X-Device-Id', 'X-Guest-Id', 'X-Client-Id'] as $header) {
            $value = trim((string) $request->header($header));
            if ($value !== '') {
                return 'device:'.hash('sha256', $value);
            }
        }

        $fingerprint = implode('|', array_filter([
            (string) $request->header('User-Agent'),
            (string) $request->header('Accept-Language'),
            (string) $request->header('Accept'),
        ]));

        return 'anon:'.hash('sha256', $fingerprint !== '' ? $fingerprint : 'fallback');
    }

    private function normalizeIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value) => is_numeric($value) ? (int) $value : null,
            $values
        ), static fn ($value) => $value !== null));
    }

    private function normalizeTerms(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $terms
        )));
    }
}
