<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedCardResource;
use App\Models\Subscription;
use App\Models\UserFollow;
use App\Models\VideoBookmark;
use App\Models\VideoLike;
use App\Models\Video;
use App\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feedService)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $feed = $this->feedService->getFeed(
            userId: (int) $request->user()->id,
            limit: (int) ($validated['limit'] ?? 20),
            page: (int) ($validated['page'] ?? 1),
        );

        $videos = $this->loadVideosInFeedOrder(
            collect($feed['data']),
            (int) $request->user()->id
        );

        return response()->json([
            'data' => FeedCardResource::collection($videos),
            'meta' => [
                'cache_hit' => $feed['cache_hit'],
                'cache_key' => $feed['cache_key'],
                'pagination' => $feed['pagination'] ?? null,
            ],
        ]);
    }

    /**
     * The feed service returns ranked identifiers and lightweight payloads.
     * We reload the models here so the response can be shaped for the mobile feed card.
     */
    private function loadVideosInFeedOrder(Collection $feedItems, int $currentUserId): Collection
    {
        $videoIds = $feedItems
            ->pluck('id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->values();

        if ($videoIds->isEmpty()) {
            return collect();
        }

        $videosById = Video::query()
            ->with('user')
            ->withCount(['likes', 'comments', 'bookmarks'])
            ->whereIn('id', $videoIds)
            ->get()
            ->keyBy('id');

        $creatorIds = $videosById
            ->pluck('user_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        $likedVideoIds = VideoLike::query()
            ->where('user_id', $currentUserId)
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $bookmarkedVideoIds = VideoBookmark::query()
            ->where('user_id', $currentUserId)
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $followedCreatorIds = UserFollow::query()
            ->where('follower_id', $currentUserId)
            ->whereIn('followed_id', $creatorIds)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $subscribedCreatorIds = Subscription::query()
            ->where('subscriber_id', $currentUserId)
            ->where('status', 'active')
            ->whereIn('creator_id', $creatorIds)
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $videosById->each(function (Video $video) use ($likedVideoIds, $bookmarkedVideoIds, $followedCreatorIds, $subscribedCreatorIds): void {
            $video->setAttribute('is_liked', $likedVideoIds->has((int) $video->id));
            $video->setAttribute('is_bookmarked', $bookmarkedVideoIds->has((int) $video->id));
            $video->setAttribute('is_following', $followedCreatorIds->has((int) $video->user_id));
            $video->setAttribute('is_subscribed', $subscribedCreatorIds->has((int) $video->user_id));
        });

        return $videoIds
            ->map(static fn (int $id) => $videosById->get($id))
            ->filter()
            ->values();
    }
}
