<?php

namespace App\Http\Resources;

use App\Models\ChallengeEntry;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Video $video */
        $video = $this->resource;
        $creator = $video->relationLoaded('user') ? $video->user : null;
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $viewerId = (int) ($request->user()?->id ?? 0);
        $isOwner = $viewerId > 0 && (int) $video->user_id === $viewerId;
        $canDuet = $isOwner || ((bool) $video->allow_duet && $video->status === 'ready' && $video->visibility === 'public');
        $challengeContext = $this->resolveChallengeContext($video, $request);
        $isDuet = $this->isDuetVideo($video, $metadata);

        $creatorName = $creator?->name ?: $creator?->username ?: 'Unknown Creator';
        $creatorHandle = $creator?->username ? ltrim((string) $creator->username, '@') : null;
        $purpose = $video->purpose instanceof \App\Enums\VideoPurpose
            ? $video->purpose->value
            : (string) $video->purpose;
        $isChallengeVideo = in_array($purpose, [
            \App\Enums\VideoPurpose::ChallengeVideo->value,
            \App\Enums\VideoPurpose::ChallengeInstructionVideo->value,
            \App\Enums\VideoPurpose::ChallengeEntry->value,
        ], true) || (bool) data_get($video, 'challenge_entries_exists', data_get($video, 'challengeEntries_exists', false)) || $challengeContext !== null;

        return [
            'id' => (string) $video->id,
            'creator' => $creatorName,
            'creatorId' => (string) ($creator?->id ?: data_get($metadata, 'creator_id')),
            'handle' => $creatorHandle,
            'avatar' => $creator?->avatar,
            'banner' => $creator?->banner,
            'caption' => (string) ($video->caption ?: $video->title ?: ''),
            'contentType' => $video->content_type,
            'contentTypes' => is_array($video->content_types) ? $video->content_types : [],
            'hashtags' => data_get($metadata, 'caption_hashtags', []),
            'mentions' => data_get($metadata, 'caption_mentions', []),
            'background' => $video->thumbnail_url ?: data_get($metadata, 'background'),
            'video' => $video->playback_url,
            'likes' => $this->formatCount($video->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
            'comments' => $this->formatCount($video->comments_count ?? data_get($metadata, 'comments', data_get($metadata, 'comments_count', 0))),
            'shares' => $this->formatCount(data_get($metadata, 'shares', data_get($metadata, 'shares_count', 0))),
            'views' => $this->formatCount($video->views_count ?? data_get($metadata, 'views', data_get($metadata, 'views_count', 0))),
            'engagement' => [
                'likes' => $this->formatCount($video->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
                'comments' => $this->formatCount($video->comments_count ?? data_get($metadata, 'comments', data_get($metadata, 'comments_count', 0))),
                'shares' => $this->formatCount(data_get($metadata, 'shares', data_get($metadata, 'shares_count', 0))),
                'views' => $this->formatCount($video->views_count ?? data_get($metadata, 'views', data_get($metadata, 'views_count', 0))),
            ],
            'isLiked' => (bool) ($video->is_liked ?? data_get($metadata, 'is_liked', false)),
            'isSubscribed' => (bool) ($video->is_subscribed ?? data_get($metadata, 'is_subscribed', false)),
            'isPremium' => $video->visibility === 'premium' || (bool) data_get($metadata, 'is_premium', false),
            'ticketsAvailable' => (bool) data_get($metadata, 'tickets_available', false),
            'ticketLocation' => data_get($metadata, 'ticket_location'),
            'originalSound' => (bool) data_get($metadata, 'original_sound', true),
            'soundArtist' => data_get($metadata, 'sound_artist'),
            'soundTitle' => data_get($metadata, 'sound_title'),
            'following' => (bool) ($video->is_following ?? data_get($metadata, 'following', false)),
            'isChallenge' => $isChallengeVideo,
            'isCreatorBattle' => $challengeContext !== null,
            'creatorBattle' => $challengeContext,
            'allowDuet' => (bool) ($video->allow_duet ?? false),
            'isDuet' => $isDuet,
            'duetSourceVideoId' => $video->duet_source_video_id ? (int) $video->duet_source_video_id : data_get($metadata, 'duet_source_video_id'),
            'duet' => $isDuet ? $this->buildDuetPayload($video, $creator, $metadata, $request) : null,
            'permissions' => [
                'allow_duet' => (bool) ($video->allow_duet ?? false),
                'allow_remix' => (bool) data_get($metadata, 'allow_remix', false),
                'allow_download' => (bool) data_get($metadata, 'allow_download', false),
            ],
            'current_user' => [
                'liked' => (bool) ($video->is_liked ?? data_get($metadata, 'is_liked', false)),
                'following_creator' => (bool) ($video->is_following ?? data_get($metadata, 'following', false)),
                'can_duet' => $canDuet,
            ],
            'canDuet' => $canDuet,
            'bookmarks' => $this->formatCount($video->bookmarks_count ?? data_get($metadata, 'bookmarks', data_get($metadata, 'bookmarks_count', 0))),
            'saves' => $this->formatCount($video->bookmarks_count ?? data_get($metadata, 'saves', data_get($metadata, 'saves_count', 0))),
            'audio' => [
                'title' => $this->resolveAudioTitle($video, $metadata),
                'is_original' => (bool) data_get($metadata, 'is_original', true),
            ],
            'created_at' => optional($video->created_at)?->toISOString(),
            'updated_at' => optional($video->updated_at)?->toISOString(),
        ];
    }

    private function buildDuetPayload(Video $video, ?User $creator, array $metadata, Request $request): array
    {
        $sourceVideo = $this->resolveDuetSourceVideo($video);

        return [
            'source_video' => $sourceVideo ? $this->buildVideoSnapshot($sourceVideo, null) : null,
            'response_video' => $this->buildVideoSnapshot($video, $creator),
            'composition' => [
                'id' => (string) data_get($metadata, 'duet_composition_id', 'composition_'.$video->id),
                'layout' => data_get($metadata, 'duet_layout', 'side_by_side'),
                'source_position' => data_get($metadata, 'duet_source_position', 'left'),
                'response_position' => data_get($metadata, 'duet_response_position', 'right'),
                'source_start_ms' => (int) data_get($metadata, 'duet_source_start_ms', 0),
                'response_start_ms' => (int) data_get($metadata, 'duet_response_start_ms', 0),
                'duration_ms' => (int) ($video->duration_ms ?? data_get($metadata, 'duet_duration_ms', 0)),
                'audio' => [
                    'source_volume' => (float) data_get($metadata, 'duet_source_volume', 0.7),
                    'response_volume' => (float) data_get($metadata, 'duet_response_volume', 1.0),
                ],
                'render_status' => data_get($metadata, 'duet_render_status', 'completed'),
            ],
        ];
    }

    private function buildVideoSnapshot(Video $video, ?User $creator = null): array
    {
        if (! $creator instanceof User) {
            if (! $video->relationLoaded('user')) {
                $video->loadMissing('user');
            }

            $creator = $video->relationLoaded('user') ? $video->user : null;
        }

        return [
            'id' => (string) $video->id,
            'creator' => $this->buildCreatorSnapshot($creator),
            'thumbnail_url' => $video->thumbnail_url,
            'duration' => is_numeric($video->duration) ? (float) $video->duration : null,
            'stream_url' => $video->playback_url,
            'status' => $video->status,
            'width' => $video->width,
            'height' => $video->height,
            'format' => 'hls',
        ];
    }

    private function buildCreatorSnapshot(?User $creator): ?array
    {
        if (! $creator) {
            return null;
        }

        return [
            'id' => (string) $creator->id,
            'name' => $creator->name,
            'username' => $creator->username,
            'avatar' => $creator->avatar,
            'verified' => (bool) ($creator->verified ?? false),
        ];
    }

    private function resolveDuetSourceVideo(Video $video): ?Video
    {
        if ($video->relationLoaded('duetSourceVideo')) {
            return $video->duetSourceVideo;
        }

        if (! $video->duet_source_video_id) {
            return null;
        }

        return $video->duetSourceVideo()->with('user')->first();
    }

    private function isDuetVideo(Video $video, array $metadata): bool
    {
        return (bool) ($video->duet_source_video_id ?? data_get($metadata, 'duet_source_video_id'));
    }

    private function resolveAudioTitle(Video $video, array $metadata): string
    {
        $title = data_get($metadata, 'audio_title') ?: data_get($metadata, 'sound_title');

        if (is_string($title) && $title !== '') {
            return $title;
        }

        if (is_string($video->title) && $video->title !== '') {
            return 'Original sound - '.$video->title;
        }

        return 'Original sound';
    }

    private function resolveChallengeContext(Video $video, Request $request): ?array
    {
        $challengeEntry = $this->resolveChallengeEntry($video);
        $challenge = $challengeEntry?->challenge;

        if (! $challenge || ! $challenge->isCreatorBattle()) {
            return null;
        }

        $challenge->loadMissing([
            'creator:id,name,username,avatar,verified',
            'rules',
            'prizes',
            'rewardPools',
            'media.video',
            'entries' => fn ($query) => $query->with(['creator:id,name,username,avatar,verified', 'video'])->orderByDesc('current_score')->latest('submitted_at'),
            'scoringComponents',
            'juryCriteria',
            'collaborators',
            'invites',
            'ballots.choices.entry',
            'winners.entry.creator',
            'winners.entry.video',
        ]);

        $battle = ChallengeResource::make($challenge)->resolve($request);
        $battle['focus_entry_id'] = $challengeEntry?->id;
        $battle['focus_creator_id'] = $challengeEntry?->creator_id ? (string) $challengeEntry->creator_id : null;
        $battle['is_creator_battle_video'] = true;

        return $battle;
    }

    private function resolveChallengeEntry(Video $video): ?ChallengeEntry
    {
        if ($video->relationLoaded('challengeEntries')) {
            return $video->challengeEntries->first();
        }

        return $video->challengeEntries()->with('challenge')->orderBy('id')->first();
    }

    private function formatCount(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return '0';
        }

        $value = (float) $value;

        if ($value >= 1000000000) {
            return rtrim(rtrim(number_format($value / 1000000000, 1), '0'), '.').'B';
        }

        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.').'M';
        }

        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'K';
        }

        return (string) (int) $value;
    }
}
