<?php

namespace App\Http\Resources;

use App\Models\VideoComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var VideoComment $comment */
        $comment = $this->resource;
        $metadata = is_array($comment->metadata ?? null) ? $comment->metadata : [];
        $user = $comment->relationLoaded('user') ? $comment->user : null;

        return [
            'id' => (string) $comment->id,
            'handle' => $this->resolveHandle($user?->username, $user?->name),
            'avatar' => $user?->avatar,
            'text' => (string) $comment->body,
            'stickerUrl' => data_get($metadata, 'stickerUrl', data_get($metadata, 'sticker_url')),
            'gift' => data_get($metadata, 'gift'),
            'time' => optional($comment->created_at)?->diffForHumans(),
            'likes' => (int) ($comment->likes_count ?? data_get($metadata, 'likes', data_get($metadata, 'likes_count', 0))),
            'verified' => (bool) ($user?->verified ?? false),
            'reply' => $this->replyPreview($comment),
        ];
    }

    private function resolveHandle(?string $username, ?string $name): string
    {
        $handle = $username ?: $name ?: 'unknown';

        return ltrim((string) $handle, '@');
    }

    private function replyPreview(VideoComment $comment): ?array
    {
        $reply = $comment->relationLoaded('replies')
            ? $comment->replies->first()
            : null;

        if (! $reply instanceof VideoComment) {
            return null;
        }

        $replyUser = $reply->relationLoaded('user') ? $reply->user : null;

        return [
            'handle' => $this->resolveHandle($replyUser?->username, $replyUser?->name),
            'avatar' => $replyUser?->avatar,
            'text' => (string) $reply->body,
            'time' => optional($reply->created_at)?->diffForHumans(),
        ];
    }
}
