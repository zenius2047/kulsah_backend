<?php

namespace App\Http\Resources;

use App\Models\CommunityPostComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityPostCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CommunityPostComment $comment */
        $comment = $this->resource;
        $author = $comment->relationLoaded('user') ? $comment->user : null;

        return [
            'id' => $comment->id,
            'post_id' => $comment->community_post_id,
            'parent_id' => $comment->parent_id,
            'content' => (string) $comment->body,
            'author' => [
                'id' => $comment->user_id,
                'name' => $author?->name,
                'handle' => ltrim((string) ($author?->username ?: $author?->name ?: 'unknown'), '@'),
                'avatar_url' => $author?->avatar,
                'is_verified' => (bool) ($author?->verified ?? false),
            ],
            'stats' => [
                'likes_count' => 0,
                'replies_count' => (int) ($comment->replies_count ?? 0),
            ],
            'viewer' => [
                'is_liked' => false,
                'can_edit' => (string) $request->user()?->id === (string) $comment->user_id,
                'can_delete' => (string) $request->user()?->id === (string) $comment->user_id,
            ],
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->values()->map(fn (CommunityPostComment $reply) => (new self($reply))->resolve($request))->all()
                : [],
            'created_at' => optional($comment->created_at)?->toIso8601String(),
            'updated_at' => optional($comment->updated_at)?->toIso8601String(),
        ];
    }

}
