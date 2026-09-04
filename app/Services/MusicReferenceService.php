<?php

namespace App\Services;

use App\Enums\MusicProvider;
use App\Models\MusicTrackReference;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MusicReferenceService
{
    public function recordUsage(array $track, int $userId): ?MusicTrackReference
    {
        $normalizedId = $this->normalizedId($track);

        if ($normalizedId === null) {
            return null;
        }

        $provider = strtolower((string) data_get($track, 'provider', MusicProvider::Audius->value));
        $externalId = (string) data_get($track, 'external_id');
        $reference = MusicTrackReference::query()->firstOrNew([
            'normalized_id' => $normalizedId,
        ]);

        $reference->fill([
            'provider' => $provider,
            'external_id' => $externalId,
            'title_snapshot' => $this->stringOrNull(data_get($track, 'title')),
            'artist_snapshot' => $this->stringOrNull(data_get($track, 'artist')),
            'artist_id_snapshot' => $this->stringOrNull(data_get($track, 'artist_id')),
            'artist_username_snapshot' => $this->stringOrNull(data_get($track, 'artist_username')),
            'artwork_snapshot' => is_array(data_get($track, 'artwork')) ? data_get($track, 'artwork') : null,
            'duration_snapshot' => is_numeric(data_get($track, 'duration')) ? (int) data_get($track, 'duration') : null,
            'source_permalink' => $this->stringOrNull(data_get($track, 'permalink')),
            'source_url' => $this->stringOrNull(data_get($track, 'stream_url')),
            'metadata' => Arr::except($track, ['usage_count', 'is_saved']),
        ]);

        if (! $reference->exists) {
            $reference->usage_count = 0;
            $reference->first_used_at = now();
        }

        $reference->last_used_at = now();
        $reference->usage_count = (int) ($reference->usage_count ?? 0) + 1;
        $reference->save();

        return $reference->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<string, int>
     */
    public function usageCountsFor(array $tracks): array
    {
        $ids = collect($tracks)
            ->map(fn (array $track): ?string => $this->normalizedId($track))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return MusicTrackReference::query()
            ->whereIn('normalized_id', $ids)
            ->pluck('usage_count', 'normalized_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    public function usageCountForIdentifier(string $identifier): int
    {
        return (int) MusicTrackReference::query()
            ->where('normalized_id', $identifier)
            ->value('usage_count');
    }

    /**
     * @param  array<string, mixed>  $track
     */
    private function normalizedId(array $track): ?string
    {
        $provider = strtolower((string) data_get($track, 'provider', ''));
        $externalId = trim((string) data_get($track, 'external_id', ''));

        if ($provider === '' || $externalId === '') {
            return null;
        }

        return $provider.':'.$externalId;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
