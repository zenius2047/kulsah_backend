<?php

namespace App\Http\Resources;

use App\Models\CommunityPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CommunityPost $post */
        $post = $this->resource;
        $author = $post->relationLoaded('user') ? $post->user : null;
        $poll = is_array($post->poll) ? $post->poll : [];
        $comments = $post->relationLoaded('comments')
            ? $post->comments->values()->map(fn ($comment) => (new CommunityPostCommentResource($comment))->resolve($request))->all()
            : [];

        return [
            'id' => $post->id,
            'type' => $post->type,
            'content' => $post->content,
            'audience' => $post->audience,
            'status' => $post->status ?? 'published',
            'author' => [
                'id' => $post->user_id,
                'name' => $author?->name,
                'handle' => ltrim((string) ($author?->username ?: $author?->name ?: 'unknown'), '@'),
                'avatar_url' => $author?->avatar,
                'role' => $this->resolveRole($author),
                'is_verified' => (bool) ($author?->verified ?? false),
                'is_following' => (bool) ($this->is_following ?? false),
            ],
            'media' => $this->formatMedia($post),
            'poll' => $this->formatPoll($poll, $request),
            'live' => null,
            'stats' => [
                'likes_count' => (int) ($post->likes_count ?? 0),
                'comments_count' => (int) ($post->comments_count ?? 0),
                'shares_count' => (int) ($post->shares_count ?? 0),
                'gifts_count' => (int) ($post->gifts_count ?? 0),
                'views_count' => (int) ($post->views_count ?? 0),
            ],
            'comments' => $comments,
            'comments_pagination' => $this->formatCommentsPagination($post),
            'viewer' => [
                'is_liked' => (bool) ($this->is_liked ?? false),
                'reaction' => null,
                'can_edit' => (string) $request->user()?->id === (string) $post->user_id,
                'can_delete' => (string) $request->user()?->id === (string) $post->user_id,
                'can_view' => true,
            ],
            'created_at' => optional($post->created_at)?->toIso8601String(),
            'updated_at' => optional($post->updated_at)?->toIso8601String(),
        ];
    }

    private function resolveRole($author): string
    {
        $role = null;

        if ($author && $author->relationLoaded('roles') && $author->roles->isNotEmpty()) {
            $role = $author->roles->first()?->name;
        }

        return is_string($role) && $role !== '' ? $role : 'creator';
    }

    private function formatMedia(CommunityPost $post): array
    {
        if ($post->relationLoaded('media') && $post->media->isNotEmpty()) {
            return $post->media->values()->map(function ($media): array {
                $mediaType = (string) ($media->media_type ?? '');
                $primaryUrl = $mediaType === 'video'
                    ? ($media->cloudinary_stream_url ?: $media->cloudinary_url ?: $media->source_url)
                    : ($media->cloudinary_url ?: $media->source_url);

                return [
                    'id' => $media->id,
                    'type' => $mediaType !== '' ? $mediaType : null,
                    'url' => $primaryUrl,
                    'source_url' => $media->source_url,
                    'cloudinary_url' => $media->cloudinary_url,
                    'cloudinary_stream_url' => $media->cloudinary_stream_url,
                    'thumbnail_url' => $media->cloudinary_thumbnail_url,
                    'mime_type' => $media->mime_type,
                    'sort_order' => (int) ($media->sort_order ?? 0),
                ];
            })->all();
        }

        $mediaIds = $post->media_ids;

        if (! is_array($mediaIds)) {
            return [];
        }

        return array_values(array_map(
            static fn ($mediaId) => [
                'id' => is_numeric($mediaId) ? (int) $mediaId : $mediaId,
                'type' => null,
                'url' => is_string($mediaId) ? $mediaId : null,
            ],
            $mediaIds,
        ));
    }

    private function formatPoll(array $poll, Request $request): array
    {
        $optionTexts = array_values(array_filter($poll['options'] ?? [], static fn ($option) => is_string($option) && trim($option) !== ''));
        /** @var CommunityPost $post */
        $post = $this->resource;
        $votes = $post->relationLoaded('pollVotes') ? $post->pollVotes : collect();
        $totalVotes = $votes->count();
        $viewerVote = $votes->firstWhere('user_id', $request->user()?->id);
        $options = [];

        foreach ($optionTexts as $index => $optionText) {
            $votesCount = $votes->where('poll_option_index', $index)->count();

            $options[] = [
                'id' => $index + 1,
                'text' => $optionText,
                'votes_count' => $votesCount,
                'percentage' => $totalVotes > 0 ? round(($votesCount / $totalVotes) * 100, 2) : 0,
                'is_selected' => $viewerVote !== null && (int) $viewerVote->poll_option_index === $index,
            ];
        }

        return [
            'options' => $options,
            'total_votes' => $totalVotes,
            'has_voted' => $viewerVote !== null,
            'selected_option_id' => $viewerVote !== null ? (int) $viewerVote->poll_option_index + 1 : null,
            'closes_at' => $poll['closes_at'] ?? null,
        ];
    }

    private function formatCommentsPagination(CommunityPost $post): array
    {
        if (! $post->relationLoaded('comments')) {
            return [
                'next_cursor' => null,
                'has_more' => false,
            ];
        }

        return [
            'next_cursor' => null,
            'has_more' => false,
        ];
    }
}
