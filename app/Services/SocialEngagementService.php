<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoLike;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialEngagementService
{
    public function likeVideo(User $user, Video $video): array
    {
        $videoId = (int) $video->getKey();
        $created = false;

        DB::transaction(function () use ($user, $videoId, &$created): void {
            $like = VideoLike::query()->firstOrCreate([
                'video_id' => $videoId,
                'user_id' => $user->id,
            ]);

            $created = $like->wasRecentlyCreated;
        });

        return $this->videoState($user, $video, [
            'isLiked' => true,
            'created' => $created,
            'message' => $created ? 'Video liked successfully.' : 'Video was already liked.',
        ]);
    }

    public function unlikeVideo(User $user, Video $video): array
    {
        $videoId = (int) $video->getKey();

        VideoLike::query()
            ->where('video_id', $videoId)
            ->where('user_id', $user->id)
            ->delete();

        return $this->videoState($user, $video, [
            'isLiked' => false,
            'created' => false,
            'message' => 'Video unliked successfully.',
        ]);
    }

    public function bookmarkVideo(User $user, Video $video): array
    {
        $videoId = (int) $video->getKey();
        $created = false;

        DB::transaction(function () use ($user, $videoId, &$created): void {
            $bookmark = VideoBookmark::query()->firstOrCreate([
                'video_id' => $videoId,
                'user_id' => $user->id,
            ]);

            $created = $bookmark->wasRecentlyCreated;
        });

        return $this->videoState($user, $video, [
            'isBookmarked' => true,
            'created' => $created,
            'message' => $created ? 'Video bookmarked successfully.' : 'Video was already bookmarked.',
        ]);
    }

    public function unbookmarkVideo(User $user, Video $video): array
    {
        $videoId = (int) $video->getKey();

        VideoBookmark::query()
            ->where('video_id', $videoId)
            ->where('user_id', $user->id)
            ->delete();

        return $this->videoState($user, $video, [
            'isBookmarked' => false,
            'created' => false,
            'message' => 'Video bookmark removed successfully.',
        ]);
    }

    public function followCreator(User $user, User $creator): array
    {
        if ((string) $user->id === (string) $creator->id) {
            throw ValidationException::withMessages([
                'creator_id' => 'You cannot follow yourself.',
            ]);
        }

        $created = false;

        DB::transaction(function () use ($user, $creator, &$created): void {
            $follow = UserFollow::query()
                ->firstOrCreate([
                    'follower_id' => $user->id,
                    'followed_id' => $creator->id,
                ]);

            $created = $follow->wasRecentlyCreated;
        });

        return [
            'message' => $created ? 'Creator followed successfully.' : 'You were already following this creator.',
            'data' => [
                'creator_id' => $creator->id,
                'follower_id' => $user->id,
                'following' => true,
                'is_following' => true,
            ],
        ];
    }

    public function unfollowCreator(User $user, User $creator): array
    {
        UserFollow::query()
            ->where('follower_id', $user->id)
            ->where('followed_id', $creator->id)
            ->delete();

        return [
            'message' => 'Creator unfollowed successfully.',
            'data' => [
                'creator_id' => $creator->id,
                'follower_id' => $user->id,
                'following' => false,
                'is_following' => false,
            ],
        ];
    }

    public function commentOnVideo(User $user, Video $video, string $body, ?int $parentId = null): array
    {
        $videoId = (int) $video->getKey();

        if ($parentId !== null) {
            $parentComment = VideoComment::query()
                ->where('id', $parentId)
                ->where('video_id', $videoId)
                ->first();

            if (! $parentComment) {
                throw (new ModelNotFoundException())->setModel(VideoComment::class, [$parentId]);
            }
        }

        $comment = VideoComment::query()->create([
            'video_id' => $videoId,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'body' => $body,
        ]);

        $comment->load('user:id,name,username,avatar');

        return [
            'message' => $parentId ? 'Reply added successfully.' : 'Comment added successfully.',
            'data' => [
                'id' => $comment->id,
                'video_id' => $comment->video_id,
                'parent_id' => $comment->parent_id,
                'body' => $comment->body,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'username' => $comment->user->username,
                    'avatar' => $comment->user->avatar,
                ],
                'created_at' => optional($comment->created_at)?->toIso8601String(),
            ],
        ];
    }

    private function videoState(User $user, Video $video, array $payload): array
    {
        $video->refresh();

        return [
            'message' => $payload['message'],
            'data' => [
                'video_id' => $video->id,
                'user_id' => $user->id,
                'likes_count' => (int) $video->likes()->count(),
                'comments_count' => (int) $video->comments()->count(),
                'bookmarks_count' => (int) $video->bookmarks()->count(),
                'isLiked' => (bool) ($payload['isLiked'] ?? false),
                'isBookmarked' => (bool) ($payload['isBookmarked'] ?? false),
            ],
        ];
    }
}
