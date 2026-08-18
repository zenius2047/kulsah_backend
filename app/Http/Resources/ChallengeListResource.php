<?php

namespace App\Http\Resources;

use App\Models\Challenge;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Challenge $challenge */
        $challenge = $this->resource;
        $creator = $challenge->relationLoaded('creator') ? $challenge->creator : null;
        $metadata = is_array($challenge->metadata) ? $challenge->metadata : [];

        return [
            'id' => $challenge->id,
            'creatorId' => $challenge->created_by_user_id,
            'creatorName' => $creator?->name ?: $creator?->username ?: 'Unknown Creator',
            'avatar' => $creator?->avatar,
            'category' => $this->resolveCategory($challenge, $metadata),
            'title' => $challenge->title,
            'description' => $challenge->description,
            'reward' => $this->resolveReward($challenge),
            'deadline' => optional($challenge->submission_ends_at)?->toIso8601String(),
            'participants' => isset($challenge->participants_count)
                ? (int) $challenge->participants_count
                : $challenge->entries()->distinct()->count('creator_id'),
            'image' => $this->resolveImage($challenge),
            'isNew' => $this->resolveIsNew($challenge, $metadata),
        ];
    }

    private function resolveCategory(Challenge $challenge, array $metadata): string|int|null
    {
        $category = data_get($metadata, 'category');

        if (is_string($category) && trim($category) !== '') {
            return $category;
        }

        if (isset($challenge->category_id) && $challenge->category_id !== null) {
            return $challenge->category_id;
        }

        return null;
    }

    private function resolveReward(Challenge $challenge): ?string
    {
        $prize = $challenge->relationLoaded('prizes')
            ? $challenge->prizes->first()
            : $challenge->prizes()->orderBy('rank_from')->first();

        if (! $prize instanceof ChallengePrize) {
            return data_get($challenge->metadata, 'reward');
        }

        if ($prize->amount !== null) {
            $amount = rtrim(rtrim(number_format((float) $prize->amount, 2, '.', ''), '0'), '.');

            return trim($amount.' '.($prize->currency ?: ''));
        }

        if (is_string($prize->title) && trim($prize->title) !== '') {
            return $prize->title;
        }

        return data_get($challenge->metadata, 'reward');
    }

    private function resolveImage(Challenge $challenge): ?string
    {
        if ($challenge->relationLoaded('media') && $challenge->media->isNotEmpty()) {
            $media = $challenge->media
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->first();

            if ($media instanceof ChallengeMedia) {
                $coverUrl = data_get($media->metadata, 'cover_url');

                if (is_string($coverUrl) && trim($coverUrl) !== '') {
                    return $coverUrl;
                }

                $video = $media->relationLoaded('video') ? $media->video : null;

                if ($video) {
                    return $video->poster_url ?: $video->thumbnail_url;
                }
            }
        }

        return data_get($challenge->metadata, 'image');
    }

    private function resolveIsNew(Challenge $challenge, array $metadata): bool
    {
        $isNew = data_get($metadata, 'is_new');

        if (is_bool($isNew)) {
            return $isNew;
        }

        if (is_numeric($isNew)) {
            return (bool) $isNew;
        }

        return (bool) ($challenge->created_at && $challenge->created_at->greaterThanOrEqualTo(now()->subDays(7)));
    }
}
