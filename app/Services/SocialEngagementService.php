<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFollow;
use App\Models\Video;
use App\Models\VideoBookmark;
use App\Models\VideoComment;
use App\Models\VideoCommentLike;
use App\Models\VideoLike;
use App\Http\Resources\VideoCommentResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialEngagementService
{
    public function __construct(
        private readonly FastApiRecommendationService $fastApiRecommendationService,
        private readonly FeedService $feedService,
        private readonly VideoCacheService $videoCacheService,
    ) {
    }

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

        if ($created) {
            $this->recordVideoEvent($user, 'like', $video, 1.0);
        }

        $this->invalidateVideoState($user, $video);

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

        $this->invalidateVideoState($user, $video);

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

        if ($created) {
            $this->recordVideoEvent($user, 'save', $video, 1.0);
        }

        $this->invalidateVideoState($user, $video);

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

        $this->invalidateVideoState($user, $video);

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

        if ($created) {
            $this->recordFollowEvent($user, $creator);
        }

        $this->invalidateViewerState($user);
        $this->feedService->invalidateFeedCaches();

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

        $this->invalidateViewerState($user);
        $this->feedService->invalidateFeedCaches();

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

        $comment->load([
            'user:id,name,username,avatar,banner,verified',
            'replies.user:id,name,username,avatar,banner,verified',
        ]);
        $comment->loadCount('likes');

        $this->recordVideoEvent($user, 'like', $video, 0.8);
        $this->invalidateVideoState($user, $video);

        return [
            'message' => $parentId ? 'Reply added successfully.' : 'Comment added successfully.',
            'data' => new VideoCommentResource($comment),
        ];
    }

    public function listVideoComments(Video $video, int $perPage = 20): LengthAwarePaginator
    {
        return VideoComment::query()
            ->where('video_id', $video->id)
            ->whereNull('parent_id')
            ->with([
                'user:id,name,username,avatar,banner,verified',
                'replies' => function ($query): void {
                    $query->oldest()->with('user:id,name,username,avatar,banner,verified')->withCount('likes');
                },
            ])
            ->withCount('likes')
            ->latest()
            ->paginate($perPage);
    }

    public function likeComment(User $user, VideoComment $comment): array
    {
        $created = false;

        DB::transaction(function () use ($user, $comment, &$created): void {
            $like = VideoCommentLike::query()->firstOrCreate([
                'video_comment_id' => $comment->id,
                'user_id' => $user->id,
            ]);

            $created = $like->wasRecentlyCreated;
        });

        $comment->load([
            'user:id,name,username,avatar,banner,verified',
            'replies.user:id,name,username,avatar,banner,verified',
        ]);
        $comment->loadCount('likes');

        $this->recordVideoEvent($user, 'like', $comment->video, 0.5);
        $this->invalidateVideoState($user, $comment->video);

        return [
            'message' => $created ? 'Comment liked successfully.' : 'Comment was already liked.',
            'data' => new VideoCommentResource($comment),
        ];
    }

    public function unlikeComment(User $user, VideoComment $comment): array
    {
        VideoCommentLike::query()
            ->where('video_comment_id', $comment->id)
            ->where('user_id', $user->id)
            ->delete();

        $comment->load([
            'user:id,name,username,avatar,banner,verified',
            'replies.user:id,name,username,avatar,banner,verified',
        ]);
        $comment->loadCount('likes');

        $this->invalidateVideoState($user, $comment->video);

        return [
            'message' => 'Comment unliked successfully.',
            'data' => new VideoCommentResource($comment),
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

    private function recordVideoEvent(User $user, string $eventType, Video $video, float $value = 1.0): void
    {
        $this->fastApiRecommendationService->recordEvent(
            userId: (int) $user->id,
            eventType: $eventType,
            videoId: (int) $video->id,
            value: $value
        );
    }

    private function recordFollowEvent(User $user, User $creator): void
    {
        $this->fastApiRecommendationService->recordEvent(
            userId: (int) $user->id,
            eventType: 'follow',
            value: 1.0,
            terms: array_values(array_filter([
                $creator->username,
                $creator->name,
            ]))
        );
    }

    private function invalidateVideoState(User $user, Video $video): void
    {
        $this->videoCacheService->invalidateViewer((int) $user->id);
        $this->videoCacheService->invalidateCreator((int) $video->user_id);
        $this->videoCacheService->invalidateVideo((int) $video->id);
        $this->feedService->invalidateFeedCaches();
    }

    private function invalidateViewerState(User $user): void
    {
        $this->videoCacheService->invalidateViewer((int) $user->id);
    }
}
