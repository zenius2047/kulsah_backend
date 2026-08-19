<?php

namespace App\Http\Resources;

use App\Domain\Challenges\Services\ChallengeEligibilityService;
use App\Enums\ChallengeStatus;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use App\Models\ChallengeRewardPool;
use App\Models\ChallengeRule;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $entryCount = isset($this->entries_count) ? (int) $this->entries_count : $this->entries()->count();
        $participantCount = isset($this->participants_count) ? (int) $this->participants_count : $this->entries()->distinct()->count('creator_id');
        $hasJoined = $user ? $this->entries()->where('creator_id', $user->id)->exists() : false;
        $hasVoted = $user ? $this->ballots()->where('voter_id', $user->id)->exists() : false;
        $canVote = (bool) ($user && $this->isVotingOpen());
        $officialSound = (bool) $this->official_sound_id;
        $eligibility = $user ? app(ChallengeEligibilityService::class)->evaluate($this->resource, $user) : null;
        $status = $this->resolveStatus();

        return [
            'id' => $this->id, 'title' => $this->title, 'slug' => $this->slug, 'description' => $this->description,
            'instructions' => $this->instructions, 'host_type' => $this->enumValue($this->host_type), 'host_user_id' => $this->host_user_id,
            'host_organization_id' => $this->host_organization_id, 'visibility' => $this->enumValue($this->visibility), 'status' => $this->enumValue($status),
            'judging_strategy' => $this->enumValue($this->judging_strategy), 'winner_selection_method' => $this->winner_selection_method,
            'schedule' => collect(['registration_starts_at', 'registration_ends_at', 'submission_starts_at', 'submission_ends_at', 'voting_starts_at', 'voting_ends_at', 'judging_starts_at', 'judging_ends_at', 'results_publish_at'])->mapWithKeys(fn ($key) => [$key => optional($this->{$key})?->toIso8601String()])->all(),
            'leaderboard' => ['enabled' => (bool) $this->show_leaderboard, 'mode' => $this->leaderboard_mode],
            'participant_count' => $participantCount, 'entry_count' => $entryCount,
            'current_phase' => $this->enumValue($status), 'time_remaining_seconds' => $this->timeRemaining($status),
            'can_join' => $user && $this->isAcceptingSubmissions() && ($eligibility['eligible'] ?? false) && ! ($this->max_entries_per_creator <= $this->entries()->where('creator_id', $user->id)->count()),
            'can_vote' => $canVote, 'has_user_joined' => $hasJoined, 'has_user_voted' => $hasVoted,
            'eligibility' => $eligibility, 'official_sound_id' => $this->official_sound_id,
            'official_video' => $this->formatOfficialVideo(),
            'reward' => $this->resolveReward(),
            'awards' => ChallengePrizeResource::collection($this->whenLoaded('prizes')),
            'prizes' => ChallengePrizeResource::collection($this->whenLoaded('prizes')),
            'rules' => $this->formatRules(),
            'reward_pools' => $this->formatRewardPools(),
            'media' => ChallengeMediaResource::collection($this->whenLoaded('media')),
            'scoring_components' => $this->whenLoaded('scoringComponents'), 'jury_criteria' => $this->whenLoaded('juryCriteria'),
            'created_at' => optional($this->created_at)?->toIso8601String(), 'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function resolveReward(): ?string
    {
        $prize = $this->relationLoaded('prizes')
            ? $this->prizes->first()
            : $this->prizes()->orderBy('rank_from')->first();

        if (! $prize instanceof ChallengePrize) {
            return data_get($this->metadata, 'reward');
        }

        if ($prize->amount !== null) {
            $amount = rtrim(rtrim(number_format((float) $prize->amount, 2, '.', ''), '0'), '.');

            return trim($amount.' '.($prize->currency ?: ''));
        }

        if (is_string($prize->title) && trim($prize->title) !== '') {
            return $prize->title;
        }

        return data_get($this->metadata, 'reward');
    }

    private function formatRules(): array
    {
        $rules = $this->relationLoaded('rules')
            ? $this->rules
            : $this->rules()->orderBy('scope')->orderBy('id')->get();

        return $rules->values()->map(function ($rule): array {
            /** @var ChallengeRule $rule */
            return [
                'id' => $rule->id,
                'scope' => $rule->scope,
                'rule_type' => $rule->rule_type,
                'operator' => $rule->operator,
                'value' => $rule->value,
                'is_required' => (bool) $rule->is_required,
                'rules_version' => $rule->rules_version,
            ];
        })->all();
    }

    private function formatRewardPools(): array
    {
        $pools = $this->relationLoaded('rewardPools')
            ? $this->rewardPools
            : $this->rewardPools()->orderBy('id')->get();

        return $pools->values()->map(function ($pool): array {
            /** @var ChallengeRewardPool $pool */
            return [
                'id' => $pool->id,
                'sponsor_id' => $pool->sponsor_id,
                'funding_source_type' => $pool->funding_source_type,
                'funding_source_id' => $pool->funding_source_id,
                'currency' => $pool->currency,
                'committed_amount' => $pool->committed_amount,
                'funded_amount' => $pool->funded_amount,
                'reserved_amount' => $pool->reserved_amount,
                'distributed_amount' => $pool->distributed_amount,
                'status' => $pool->status,
                'funded_at' => optional($pool->funded_at)?->toIso8601String(),
            ];
        })->all();
    }

    private function formatOfficialVideo(): ?array
    {
        $media = $this->resolveOfficialMedia();
        $video = null;

        if ($media && $media->relationLoaded('video') && $media->video) {
            $video = $media->video;
        } elseif ($this->official_sound_id) {
            $video = Video::query()->find($this->official_sound_id);
        }

        if (! $video) {
            return null;
        }

        return [
            'id' => (string) $video->id,
            'role' => $media?->role,
            'videoUrl' => $video->playback_url ?: $video->streaming_url ?: $video->cdn_url ?: $video->rendered_url,
            'thumbnailUrl' => $video->poster_url ?: $video->thumbnail_url ?: data_get($video->metadata, 'thumbnail'),
            'caption' => $video->caption ?? '',
            'tag' => 'ChallengeVideo',
        ];
    }

    private function formatEntries(bool $canVote, bool $hasVoted, bool $officialSound): array
    {
        $entries = $this->relationLoaded('entries')
            ? $this->entries
            : $this->entries()->with(['creator:id,name,username,avatar', 'video'])->orderByDesc('current_score')->latest('submitted_at')->get();

        return $entries->values()->map(function ($entry) use ($canVote, $hasVoted, $officialSound): array {
            $creator = $entry->relationLoaded('creator') ? $entry->creator : $entry->creator()->first();
            $video = $entry->relationLoaded('video') ? $entry->video : $entry->video()->first();

            return [
                'id' => (string) $entry->id,
                'userName' => $creator?->name ?: $creator?->username ?: 'Unknown Creator',
                'userHandle' => $creator?->username ?: '',
                'userAvatar' => $creator?->avatar,
                'videoUrl' => $video?->playback_url ?: $video?->streaming_url ?: $video?->cdn_url ?: $video?->rendered_url,
                'thumbnailUrl' => $video?->poster_url ?: $video?->thumbnail_url ?: data_get($video?->metadata, 'thumbnail'),
                'caption' => $entry->caption ?? $video?->caption ?? '',
                'likes' => 0,
                'comments' => 0,
                'votes' => (float) ($entry->current_score ?? 0),
                'isLiked' => false,
                'isVoted' => $hasVoted,
                'originalSound' => $officialSound,
                'tag' => 'ChallengeEntry',
                'isVote' => $canVote,
            ];
        })->all();
    }

    private function resolveOfficialMedia(): ?ChallengeMedia
    {
        $roles = ['challenge_video', 'instruction_video'];

        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            foreach ($roles as $role) {
                $media = $this->media->firstWhere('role', $role);

                if ($media instanceof ChallengeMedia) {
                    return $media;
                }
            }

            $firstMedia = $this->media->first();

            return $firstMedia instanceof ChallengeMedia ? $firstMedia : null;
        }

        $query = $this->media()->with('video')->orderBy('sort_order')->orderBy('id');

        foreach ($roles as $role) {
            $media = (clone $query)->where('role', $role)->first();

            if ($media instanceof ChallengeMedia) {
                return $media;
            }
        }

        $firstMedia = $query->first();

        return $firstMedia instanceof ChallengeMedia ? $firstMedia : null;
    }

    private function resolveStatus(): mixed
    {
        $status = $this->status;

        if ($status instanceof ChallengeStatus) {
            return $status;
        }

        if (is_string($status) && $status !== '') {
            return ChallengeStatus::tryFrom($status) ?? $status;
        }

        return null;
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_object($value) && isset($value->value)) {
            return is_scalar($value->value) ? (string) $value->value : null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function timeRemaining(mixed $status = null): ?int
    {
        $status = $status instanceof ChallengeStatus ? $status : $this->resolveStatus();

        if (! $status instanceof ChallengeStatus) {
            return null;
        }

        $target = match ($status) {
            ChallengeStatus::Active => $this->submission_ends_at,
            ChallengeStatus::SubmissionsClosed => $this->voting_ends_at,
            ChallengeStatus::Judging => $this->judging_ends_at,
            default => null,
        };

        return $target ? max(0, now()->diffInSeconds($target, false)) : null;
    }
}