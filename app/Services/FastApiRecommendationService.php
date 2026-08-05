<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FastApiRecommendationService
{
    public function isEnabled(): bool
    {
        return filter_var(config('services.fastapi.enabled', true), FILTER_VALIDATE_BOOL);
    }

    public function recommend(Collection $videos, int $userId, int $limit = 20, array $context = []): ?Collection
    {
        if (! $this->isEnabled() || $videos->isEmpty()) {
            return null;
        }

        $payload = [
            'user_id' => $userId,
            'limit' => $limit,
            'search_query' => $this->stringOrNull($context['search_query'] ?? null),
            'interest_terms' => $this->normalizeTerms($context['interest_terms'] ?? []),
            'peer_strength' => (float) ($context['peer_strength'] ?? 0.0),
            'followed_creator_ids' => $this->normalizeIntegers($context['followed_creator_ids'] ?? []),
            'subscribed_creator_ids' => $this->normalizeIntegers($context['subscribed_creator_ids'] ?? []),
            'liked_video_ids' => $this->normalizeIntegers($context['liked_video_ids'] ?? []),
            'bookmarked_video_ids' => $this->normalizeIntegers($context['bookmarked_video_ids'] ?? []),
            'favorite_categories' => $this->normalizeTerms($context['favorite_categories'] ?? []),
            'favorite_creator_ids' => $this->normalizeIntegers($context['favorite_creator_ids'] ?? []),
            'vibe_terms' => $this->normalizeTerms($context['vibe_terms'] ?? []),
            'initial_feed' => (bool) ($context['initial_feed'] ?? false),
            'include_breakdown' => (bool) ($context['include_breakdown'] ?? false),
            'videos' => $videos->map(function (Video $video) use ($context): array {
                return $this->buildCandidatePayload($video, $context);
            })->values()->all(),
        ];

        $response = $this->sendSignedJson('POST', '/recommend', $payload);

        if (! $response->successful()) {
            Log::warning('FastAPI recommendation request failed.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        $orderedIds = collect($response->json('videos', []))
            ->pluck('video_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->values();

        if ($orderedIds->isEmpty()) {
            return null;
        }

        $videosById = $videos->keyBy(static fn (Video $video): int => (int) $video->id);
        $rankedVideos = $orderedIds
            ->map(static fn (int $videoId) => $videosById->get($videoId))
            ->filter()
            ->values();

        $remainingVideos = $videos
            ->reject(static fn (Video $video) => $orderedIds->contains((int) $video->id))
            ->values();

        return $rankedVideos->concat($remainingVideos)->values();
    }

    public function recordEvent(
        int $userId,
        string $eventType,
        ?int $videoId = null,
        float $value = 1.0,
        array $terms = []
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->sendSignedJson('POST', '/events', [
                'user_id' => $userId,
                'event_type' => $eventType,
                'video_id' => $videoId,
                'value' => $value,
                'terms' => $this->normalizeTerms($terms),
            ]);
        } catch (Throwable $throwable) {
            Log::debug('FastAPI event ingest failed.', [
                'event_type' => $eventType,
                'user_id' => $userId,
                'video_id' => $videoId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function client()
    {
        return Http::baseUrl(rtrim((string) config('services.fastapi.url', ''), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.fastapi.timeout', 5))
            ->retry((int) config('services.fastapi.retries', 1), 250);
    }

    private function sendSignedJson(string $method, string $path, array $payload)
    {
        $request = $this->client();
        $sharedSecret = (string) config('services.fastapi.shared_secret', '');

        if ($sharedSecret === '') {
            return $request->send($method, $path, [
                'json' => $payload,
            ]);
        }

        $timestamp = now()->utc()->timestamp;
        $body = $this->encodePayload($payload);
        $signature = $this->signRequest(
            sharedSecret: $sharedSecret,
            timestamp: $timestamp,
            method: strtoupper($method),
            path: $path,
            body: $body
        );

        return $request
            ->withHeaders([
                'X-Kulsah-Timestamp' => (string) $timestamp,
                'X-Kulsah-Signature' => $signature,
                'X-Kulsah-Service' => 'laravel',
            ])
            ->withBody($body, 'application/json')
            ->send($method, $path);
    }

    private function buildCandidatePayload(Video $video, array $context): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $contentTypes = is_array($video->content_types) ? $video->content_types : [];
        $tags = array_values(array_filter(array_merge(
            $contentTypes,
            $this->normalizeTerms(data_get($metadata, 'caption_hashtags', [])),
            $this->normalizeTerms($context['interest_terms'] ?? [])
        )));
        $createdAt = $video->created_at?->copy();
        $ageHours = $createdAt ? max(0.0, (float) now()->diffInHours($createdAt)) : null;
        $likes = (int) ($video->likes_count ?? data_get($metadata, 'likes_count', 0));
        $comments = (int) ($video->comments_count ?? data_get($metadata, 'comments_count', 0));
        $bookmarks = (int) ($video->bookmarks_count ?? data_get($metadata, 'bookmarks_count', 0));
        $views = (int) ($video->views_count ?? data_get($metadata, 'views_count', 0));
        $favoriteCategories = $this->normalizeTerms($context['favorite_categories'] ?? []);
        $vibeTerms = $this->normalizeTerms($context['vibe_terms'] ?? []);
        $favoriteCreatorIds = $this->normalizeIntegers($context['favorite_creator_ids'] ?? []);
        $followedCreatorIds = $this->normalizeIntegers($context['followed_creator_ids'] ?? []);
        $subscribedCreatorIds = $this->normalizeIntegers($context['subscribed_creator_ids'] ?? []);
        $historyAffinity = 0.0;

        if ($favoriteCategories !== []) {
            $candidateCategory = strtolower((string) ($video->content_type ?: data_get($metadata, 'category', data_get($metadata, 'topic', ''))));
            if ($candidateCategory !== '' && in_array($candidateCategory, $favoriteCategories, true)) {
                $historyAffinity += 0.18;
            }
        }

        if (in_array((int) $video->user_id, $favoriteCreatorIds, true)) {
            $historyAffinity += 0.22;
        }

        if (in_array((int) $video->user_id, $followedCreatorIds, true)) {
            $historyAffinity += 0.15;
        }

        if (in_array((int) $video->user_id, $subscribedCreatorIds, true)) {
            $historyAffinity += 0.20;
        }

        if ($vibeTerms !== []) {
            $candidateText = strtolower(implode(' ', array_filter([
                $video->title,
                $video->caption,
                $video->content_type,
                is_array($video->content_types) ? implode(' ', $video->content_types) : null,
                data_get($metadata, 'topic'),
                data_get($metadata, 'category'),
            ])));

            foreach ($vibeTerms as $vibeTerm) {
                if ($vibeTerm !== '' && str_contains($candidateText, $vibeTerm)) {
                    $historyAffinity += 0.12;
                    break;
                }
            }
        }

        return [
            'video_id' => (int) $video->id,
            'creator_id' => (int) $video->user_id,
            'title' => $video->title,
            'creator_name' => $this->creatorName($video),
            'category' => $video->content_type ?: data_get($metadata, 'category'),
            'tags' => $tags,
            'watch_time_score' => $this->clamp(
                0.45
                + ($this->recencyScore($ageHours) * 0.35)
                + ($this->completionScore($video) * 0.2)
            ),
            'engagement_score' => $this->clamp(
                (($likes * 2) + ($comments * 3) + ($bookmarks * 4) + ($views * 0.05)) / 50
            ),
            'interest_match' => $this->clamp($this->textMatchScore($video, $context)),
            'freshness' => $this->clamp($this->recencyScore($ageHours)),
            'creator_quality' => $this->clamp(
                0.55 + (($likes + $comments + $bookmarks) / max(1, $views + 20)) * 0.25
            ),
            'peer_score' => $this->peerScore($video, $context),
            'search_score' => $this->searchScore($video, $context),
            'viral_boost' => $this->clamp(
                (($views / 500) + (($likes + $comments + $bookmarks) / 100)) / 2
            ),
            'view_velocity' => (float) ($views > 0 ? min(500, $views) : 0),
            'share_velocity' => (float) data_get($metadata, 'share_velocity', 0),
            'completion_rate' => $this->completionScore($video),
            'age_hours' => $ageHours,
            'history_affinity' => $this->clamp($historyAffinity),
        ];
    }

    private function peerScore(Video $video, array $context): float
    {
        $followedCreatorIds = array_map('intval', $context['followed_creator_ids'] ?? []);
        $subscribedCreatorIds = array_map('intval', $context['subscribed_creator_ids'] ?? []);
        $score = 0.0;

        if (in_array((int) $video->user_id, $followedCreatorIds, true)) {
            $score += 0.6;
        }

        if (in_array((int) $video->user_id, $subscribedCreatorIds, true)) {
            $score += 0.4;
        }

        return $this->clamp($score);
    }

    private function searchScore(Video $video, array $context): float
    {
        $query = trim((string) ($context['search_query'] ?? ''));
        if ($query === '') {
            return 0.0;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $video->title,
            $video->caption,
            $video->content_type,
            $this->creatorName($video),
        ])));

        return $this->clamp($this->containsAnyToken($query, $haystack));
    }

    private function textMatchScore(Video $video, array $context): float
    {
        $tokens = array_merge(
            $this->normalizeTerms($context['interest_terms'] ?? []),
            $this->normalizeTerms($context['search_query'] ?? null)
        );

        if ($tokens === []) {
            return 0.35;
        }

        $text = strtolower(implode(' ', array_filter([
            $video->title,
            $video->caption,
            $video->content_type,
            is_array($video->content_types) ? implode(' ', $video->content_types) : null,
            data_get($video->metadata, 'topic'),
            data_get($video->metadata, 'category'),
        ])));

        $matches = 0;
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($text, $token)) {
                $matches++;
            }
        }

        return $matches / max(1, count($tokens));
    }

    private function recencyScore(?float $ageHours): float
    {
        if ($ageHours === null) {
            return 0.5;
        }

        return exp(-($ageHours / 48));
    }

    private function completionScore(Video $video): float
    {
        $duration = (int) ($video->duration ?? data_get($video->metadata, 'duration_seconds', 0));
        if ($duration <= 0) {
            return 0.5;
        }

        return $this->clamp(1 - min($duration, 300) / 300);
    }

    private function containsAnyToken(string $needle, string $haystack): float
    {
        $tokens = $this->normalizeTerms($needle);
        if ($tokens === []) {
            return 0.0;
        }

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function normalizeTerms(mixed $terms): array
    {
        if (is_string($terms)) {
            $terms = preg_split('/\s*,\s*/', trim($terms), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($term) => is_string($term) ? strtolower(trim($term)) : '',
            $terms
        )));
    }

    private function normalizeIntegers(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value) => is_numeric($value) ? (int) $value : null,
            $values
        ), static fn ($value) => $value !== null));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }

    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function signRequest(string $sharedSecret, int $timestamp, string $method, string $path, string $body): string
    {
        $canonical = implode("\n", [
            (string) $timestamp,
            strtoupper($method),
            $path,
            $body,
        ]);

        return hash_hmac('sha256', $canonical, $sharedSecret);
    }

    private function creatorName(Video $video): ?string
    {
        if (! $video->relationLoaded('user')) {
            return null;
        }

        return $video->user?->name ?: $video->user?->username;
    }
}
