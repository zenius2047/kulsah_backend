<?php

namespace App\Http\Resources;

use App\Domain\Challenges\Services\ChallengeEligibilityService;
use App\Enums\ChallengeMode;
use App\Enums\ChallengeStatus;
use App\Models\ChallengeBallot;
use App\Models\ChallengeCollaborator;
use App\Models\ChallengeEntry;
use App\Models\ChallengeInvite;
use App\Models\ChallengeMedia;
use App\Models\ChallengePrize;
use App\Models\ChallengeRewardPool;
use App\Models\ChallengeRule;
use App\Models\ChallengeWinner;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->isCreatorBattle()) {
            return $this->formatCreatorBattle($request);
        }

        $user = $request->user();
        $entryCount = isset($this->entries_count) ? (int) $this->entries_count : $this->entries()->count();
        $participantCount = $this->resolveParticipantCount();
        $hasJoined = $user ? $this->entries()->where('creator_id', $user->id)->exists() : false;
        $hasVoted = $user ? $this->ballots()->where('voter_id', $user->id)->exists() : false;
        $canVote = (bool) ($user && $this->isVotingOpen());
        $officialSound = (bool) $this->official_sound_id;
        $eligibility = $user ? app(ChallengeEligibilityService::class)->evaluate($this->resource, $user) : null;
        $status = $this->resolveStatus();
        $mode = $this->resolveMode();

        return [
            'id' => $this->id, 'title' => $this->title, 'slug' => $this->slug, 'description' => $this->description,
            'instructions' => $this->instructions, 'host_type' => $this->enumValue($this->host_type), 'host_user_id' => $this->host_user_id,
            'host_organization_id' => $this->host_organization_id, 'visibility' => $this->enumValue($this->visibility), 'mode' => $this->enumValue($mode),
            'is_creator_battle' => $mode === ChallengeMode::CreatorBattle, 'status' => $this->enumValue($status),
            'judging_strategy' => $this->enumValue($this->judging_strategy), 'winner_selection_method' => $this->winner_selection_method,
            'schedule' => collect(['registration_starts_at', 'registration_ends_at', 'submission_starts_at', 'submission_ends_at', 'voting_starts_at', 'voting_ends_at', 'judging_starts_at', 'judging_ends_at', 'results_publish_at'])->mapWithKeys(fn ($key) => [$key => optional($this->{$key})?->toIso8601String()])->all(),
            'leaderboard' => ['enabled' => (bool) $this->show_leaderboard, 'mode' => $this->leaderboard_mode],
            'participant_limit' => $this->max_participants,
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

    private function formatCreatorBattle(Request $request): array
    {
        $user = $request->user();
        $status = $this->resolveStatus();
        $mode = $this->resolveMode();
        $category = $this->resolveCategory();
        $coverImage = $this->resolveCoverImage();
        $participants = $this->resolveCreatorBattleParticipants($request);
        $ballots = $this->resolveCreatorBattleBallots($user);
        $voteCounts = $this->resolveCreatorBattleVoteCounts($participants, $ballots);
        $totalVotes = array_sum($voteCounts);
        $viewVote = $participants->values()
            ->map(function (array $participant) use ($voteCounts, $totalVotes): array {
                $votes = (int) ($voteCounts[$participant['creator']['id']] ?? 0);

                return [
                    'creator' => $participant['creator']['name'] ?? $participant['creator']['username'] ?? 'Unknown Creator',
                    'avatar' => $participant['creator']['avatar'] ?? null,
                    'votes' => $votes,
                    'percentage' => $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('votes')
            ->values()
            ->all();
        $currentUserVote = $this->resolveCurrentUserVote($user, $ballots);
        $hasVoted = $currentUserVote !== null;
        $votingOpen = $this->isVotingOpen();
        $canVote = (bool) ($user && $votingOpen && (! $hasVoted || (bool) data_get($this->voting_configuration, 'allow_vote_changes', false)));

        return [
            'id' => $this->id,
            'creator_id' => $this->created_by_user_id,
            'mode' => $this->enumValue($mode),
            'title' => $this->title,
            'description' => $this->description,
            'category' => $category,
            'hashtag' => $this->resolveHashtag(),
            'cover_image' => $coverImage,
            'status' => $this->enumValue($status),
            'participant_limit' => $this->max_participants,
            'participant_count' => count($participants),
            'winner_selection_method' => $this->winner_selection_method,
            'submission' => [
                'starts_at' => optional($this->submission_starts_at)?->toIso8601String(),
                'ends_at' => optional($this->submission_ends_at)?->toIso8601String(),
                'is_open' => $this->isAcceptingSubmissions(),
            ],
            'voting' => [
                'enabled' => (bool) ($this->voting_starts_at && $this->voting_ends_at),
                'status' => $this->resolveVotingStatus(),
                'starts_at' => optional($this->voting_starts_at)?->toIso8601String(),
                'ends_at' => optional($this->voting_ends_at)?->toIso8601String(),
                'total_votes' => $totalVotes,
                'visibility' => $this->resolveVotingVisibility(),
                'allow_vote_change' => (bool) data_get($this->voting_configuration, 'allow_vote_changes', false),
                'current_user_has_voted' => $hasVoted,
                'current_user_voted_entry_id' => $currentUserVote,
            ],
            'viewVote' => $viewVote,
            'viewVote' => $viewVote,
            'participants' => $participants->values()->map(function (array $participant) use ($voteCounts, $totalVotes): array {
                $votes = (int) ($voteCounts[$participant['creator']['id']] ?? 0);
                $entry = $participant['entry'];

                return [
                    'id' => $participant['id'],
                    'role' => $participant['role'],
                    'position' => $participant['position'],
                    'creator' => $participant['creator'],
                    'invitation_status' => $participant['invitation_status'],
                    'submission_status' => $participant['submission_status'],
                    'entry' => $entry,
                    'likes' => (int) data_get($entry, 'engagement.likes', 0),
                    'comments' => (int) data_get($entry, 'engagement.comments', 0),
                    'votes' => [
                        'count' => $votes,
                        'percentage' => $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 2) : 0.0,
                    ],
                    'is_winner' => $participant['is_winner'],
                ];
            })->all(),
            'current_user' => [
                'is_host' => (bool) ($user && (int) $this->created_by_user_id === (int) $user->id),
                'is_participant' => $this->resolveIsCurrentUserParticipant($user, $participants),
                'has_voted' => $hasVoted,
                'voted_entry_id' => $currentUserVote,
                'can_vote' => $canVote,
                'can_submit' => $this->resolveCanCurrentUserSubmit($user, $participants),
                'can_manage' => $this->resolveCanCurrentUserManage($user),
            ],
            'result' => $this->formatCreatorBattleResult(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function resolveCreatorBattleParticipants(Request $request)
    {
        $host = $this->creator()->first();
        $hostUser = $host instanceof User ? $host : null;
        $collaborators = $this->relationLoaded('collaborators')
            ? $this->collaborators
            : $this->collaborators()->orderBy('id')->get();
        $invites = $this->relationLoaded('invites')
            ? $this->invites
            : $this->invites()->orderBy('id')->get();
        $entries = $this->relationLoaded('entries')
            ? $this->entries
            : $this->entries()->with(['creator:id,name,username,avatar,verified', 'video'])->where('status', 'active')->orderByDesc('submitted_at')->orderBy('id')->get();

        $users = collect([$hostUser])
            ->filter()
            ->merge(
                User::query()
                    ->whereIn('id', $collaborators->pluck('user_id')->filter()->all())
                    ->get(['id', 'name', 'username', 'avatar', 'verified'])
            )
            ->keyBy('id');

        $entriesByCreator = $entries->groupBy('creator_id')->map(fn ($group) => $group->first());
        $inviteByUserId = $invites->keyBy('invited_user_id');

        $participants = collect();

        if ($hostUser) {
            $participants->push($this->formatCreatorBattleParticipant(
                user: $users->get($hostUser->id),
                role: 'host',
                position: 1,
                invitationStatus: 'accepted',
                entry: $entriesByCreator->get($hostUser->id),
                isWinner: false,
            ));
        }

        $collaborators
            ->whereIn('role', ['owner', 'challenger'])
            ->values()
            ->each(function (ChallengeCollaborator $collaborator, int $index) use ($users, $inviteByUserId, $entriesByCreator, $participants): void {
                $user = $users->get($collaborator->user_id) ?? User::query()->find($collaborator->user_id);
                if (! $user) {
                    return;
                }

                $participants->push($this->formatCreatorBattleParticipant(
                    user: $user,
                    role: $collaborator->role === 'owner' ? 'host' : 'challenger',
                    position: $participants->count() + 1,
                    invitationStatus: $inviteByUserId->get($collaborator->user_id)?->status ?? $collaborator->status ?? 'pending',
                    entry: $entriesByCreator->get($collaborator->user_id),
                    isWinner: false,
                ));
            });

        return $participants->values();
    }

    private function formatCreatorBattleParticipant(User|int|null $user, string $role, int $position, string $invitationStatus, ?ChallengeEntry $entry, bool $isWinner): array
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $displayUser = $user instanceof User ? $user : null;

        return [
            'id' => 'participant_'.$userId,
            'role' => $role,
            'position' => $position,
            'creator' => [
                'id' => $userId,
                'name' => $displayUser?->name,
                'username' => $displayUser?->username,
                'avatar' => $displayUser?->avatar,
                'verified' => (bool) $displayUser?->verified,
            ],
            'invitation_status' => $invitationStatus,
            'submission_status' => $entry ? 'submitted' : 'not_submitted',
            'entry' => $entry ? $this->formatCreatorBattleEntry($entry) : null,
            'is_winner' => $isWinner,
        ];
    }

    private function formatCreatorBattleEntry(ChallengeEntry $entry): array
    {
        $creator = $entry->relationLoaded('creator') ? $entry->creator : $entry->creator()->with('roles')->first();
        $video = $entry->relationLoaded('video') ? $entry->video : $entry->video()->first();
        $metadata = is_array($video?->metadata) ? $video->metadata : [];

        $comments = $video
            ? ($video->relationLoaded('comments')
                ? $video->comments
                : $video->comments()->with(['user', 'replies.user'])->latest()->limit(20)->get())
            : collect();

        return [
            'id' => $entry->id,
            'caption' => $entry->caption ?? $video?->caption ?? '',
            'hashtags' => $this->resolveEntryHashtags($entry, $video),
            'video' => $this->formatBattleVideo($video),
            'audio' => $this->formatBattleAudio($video),
            'comments_count' => (int) ($video?->comments_count ?? $comments->count()),
            'comments' => VideoCommentResource::collection($comments->values())->resolve($request),
            'engagement' => [
                'likes' => (int) data_get($metadata, 'engagement.likes', 0),
                'comments' => (int) data_get($metadata, 'engagement.comments', 0),
                'shares' => (int) data_get($metadata, 'engagement.shares', 0),
            ],
        ];
    }

    private function formatBattleVideo(?Video $video): ?array
    {
        if (! $video) {
            return null;
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $processingStatus = $video->processing_status?->value ?? ($video->status === 'ready' ? 'ready' : 'initialized');
        $playbackUrl = $video->playback_url ?: $video->streaming_url ?: $video->cdn_url ?: $video->rendered_url;

        return [
            'id' => $video->id,
            'status' => $video->status,
            'stream_url' => $playbackUrl,
            'thumbnail_url' => $video->poster_url ?: $video->thumbnail_url ?: data_get($metadata, 'thumbnail'),
            'duration' => $video->duration ? (float) $video->duration : null,
            'width' => $video->width,
            'height' => $video->height,
            'processing_status' => $processingStatus,
        ];
    }

    private function formatBattleAudio(?Video $video): ?array
    {
        if (! $video) {
            return null;
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $audio = data_get($metadata, 'audio');

        if (! is_array($audio) || $audio === []) {
            return null;
        }

        return [
            'id' => $audio['id'] ?? null,
            'title' => $audio['title'] ?? null,
            'artist' => $audio['artist'] ?? null,
            'is_original' => (bool) ($audio['is_original'] ?? false),
        ];
    }

    private function resolveEntryHashtags(ChallengeEntry $entry, ?Video $video): array
    {
        $metadata = is_array($video?->metadata) ? $video->metadata : [];
        $hashtags = data_get($metadata, 'caption_hashtags', []);

        return is_array($hashtags) ? array_values($hashtags) : [];
    }

    private function resolveCreatorBattleVoteCounts($participants, $ballots): array
    {
        $counts = [];

        foreach ($participants as $participant) {
            $counts[$participant['creator']['id']] = 0;
        }

        foreach ($ballots as $ballot) {
            foreach ($ballot->choices as $choice) {
                $entry = $choice->entry ?? null;
                if ($entry) {
                    $counts[$entry->creator_id] = ($counts[$entry->creator_id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    private function resolveCreatorBattleBallots(?User $user)
    {
        if (! $user) {
            return collect();
        }

        $ballots = $this->relationLoaded('ballots')
            ? $this->ballots
            : $this->ballots()->with(['choices.entry'])->orderBy('id')->get();

        return $ballots->where('voter_id', $user->id)->values();
    }

    private function resolveCurrentUserVote(?User $user, $ballots): ?int
    {
        if (! $user) {
            return null;
        }

        $ballot = $ballots->firstWhere('voter_id', $user->id);
        $choice = $ballot?->choices?->first();

        return $choice?->challenge_entry_id ? (int) $choice->challenge_entry_id : null;
    }

    private function resolveIsCurrentUserParticipant(?User $user, $participants): bool
    {
        if (! $user) {
            return false;
        }

        return $participants->contains(fn (array $participant) => (int) $participant['creator']['id'] === (int) $user->id);
    }

    private function resolveCanCurrentUserSubmit(?User $user, $participants): bool
    {
        if (! $user) {
            return false;
        }

        $isParticipant = $this->resolveIsCurrentUserParticipant($user, $participants);

        if (! $isParticipant || ! $this->isAcceptingSubmissions()) {
            return false;
        }

        $existingSubmissions = $this->entries()->where('creator_id', $user->id)->count();

        return ! ($this->max_entries_per_creator <= $existingSubmissions);
    }

    private function resolveCanCurrentUserManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ((int) $this->created_by_user_id === (int) $user->id) {
            return true;
        }

        return $user->roles()->where('name', 'admin')->exists();
    }

    private function formatCreatorBattleResult(): ?array
    {
        $winner = $this->relationLoaded('winners')
            ? $this->winners->first()
            : $this->winners()->with(['entry.creator', 'entry.video'])->orderBy('rank')->first();

        if (! $winner instanceof ChallengeWinner) {
            return null;
        }

        $entry = $winner->entry;
        $creator = $entry?->relationLoaded('creator') ? $entry->creator : $entry?->creator()->first();

        return [
            'winner_entry_id' => $winner->challenge_entry_id,
            'rank' => $winner->rank,
            'final_score' => $winner->final_score,
            'confirmed_at' => optional($winner->confirmed_at)?->toIso8601String(),
            'entry' => $entry ? $this->formatCreatorBattleEntry($entry) : null,
            'creator' => $creator ? [
                'id' => $creator->id,
                'name' => $creator->name,
                'username' => $creator->username,
                'avatar' => $creator->avatar,
                'verified' => (bool) $creator->verified,
            ] : null,
        ];
    }

    private function resolveCreatorBattleParticipantsCount(): int
    {
        return (int) $this->collaborators()->whereIn('role', ['owner', 'challenger'])->count();
    }

    private function resolveCategory(): ?array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $category = data_get($metadata, 'category');

        if (is_array($category)) {
            return [
                'id' => $category['id'] ?? null,
                'name' => $category['name'] ?? ($category['label'] ?? null),
            ];
        }

        if (is_string($category) && trim($category) !== '') {
            return ['id' => null, 'name' => $category];
        }

        if (isset($this->category_id) && $this->category_id !== null) {
            return [
                'id' => $this->category_id,
                'name' => data_get($metadata, 'category_name'),
            ];
        }

        return null;
    }

    private function resolveCoverImage(): ?string
    {
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $cover = $this->media->sortBy([['sort_order', 'asc'], ['id', 'asc']])->firstWhere('role', 'cover')
                ?? $this->media->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();

            if ($cover instanceof ChallengeMedia) {
                $coverUrl = data_get($cover->metadata, 'cover_url');

                if (is_string($coverUrl) && trim($coverUrl) !== '') {
                    return $coverUrl;
                }

                $video = $cover->relationLoaded('video') ? $cover->video : null;

                if ($video) {
                    return $video->poster_url ?: $video->thumbnail_url;
                }
            }
        }

        return data_get($this->metadata, 'cover_image')
            ?: data_get($this->metadata, 'image');
    }

    private function resolveHashtag(): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $hashtag = data_get($metadata, 'hashtag');

        if (is_string($hashtag) && trim($hashtag) !== '') {
            return $hashtag;
        }

        return is_string($this->hashtag ?? null) && trim((string) $this->hashtag) !== ''
            ? (string) $this->hashtag
            : null;
    }

    private function resolveVotingStatus(): string
    {
        if (! $this->voting_starts_at || ! $this->voting_ends_at) {
            return 'closed';
        }

        if (now()->lt($this->voting_starts_at)) {
            return 'upcoming';
        }

        if (now()->betweenIncluded($this->voting_starts_at, $this->voting_ends_at)) {
            return 'open';
        }

        return 'closed';
    }

    private function resolveVotingVisibility(): string
    {
        return (bool) $this->show_leaderboard && $this->leaderboard_mode === 'live'
            ? 'live_count'
            : 'hidden';
    }

    private function resolveParticipantCount(): int
    {
        if ($this->isCreatorBattle()) {
            return $this->resolveCreatorBattleParticipantsCount();
        }

        return isset($this->participants_count)
            ? (int) $this->participants_count
            : $this->entries()->distinct()->count('creator_id');
    }

    private function resolveMode(): ChallengeMode
    {
        $mode = $this->mode;

        if ($mode instanceof ChallengeMode) {
            return $mode;
        }

        if (is_string($mode) && $mode !== '') {
            return ChallengeMode::tryFrom($mode) ?? ChallengeMode::Open;
        }

        return ChallengeMode::Open;
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
