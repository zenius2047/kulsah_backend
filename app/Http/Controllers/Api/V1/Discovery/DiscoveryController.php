<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscoveryCreatorResource;
use App\Http\Resources\DiscoveryEventResource;
use App\Http\Resources\DiscoveryVideoResource;
use App\Services\ContentViewStateService;
use App\Services\DiscoveryService;
use App\Models\CommunityPost;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscoveryController extends Controller
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
        private readonly ContentViewStateService $contentViewStateService,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'string', Rule::in(['all', 'creators', 'events', 'videos'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search_query' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $this->discoveryService->discover(
            viewerId: (int) $request->user()->id,
            tab: (string) ($validated['tab'] ?? 'all'),
            page: (int) ($validated['page'] ?? 1),
            limit: (int) ($validated['limit'] ?? 20),
            searchQuery: trim((string) ($validated['search_query'] ?? '')),
        );

        return response()->json([
            'data' => [
                'creators' => DiscoveryCreatorResource::collection($result['creators'])->resolve($request),
                'events' => DiscoveryEventResource::collection($result['events'])->resolve($request),
                'videos' => DiscoveryVideoResource::collection($result['videos'])->resolve($request),
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'discovery_count' => $result['discovery_count'],
                'counts' => $result['counts'],
                'pagination' => [
                    'current_page' => $result['page'],
                    'per_page' => $result['limit'],
                    'has_more' => $result['has_more'],
                ],
            ],
        ]);
    }

    public function view(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['creator', 'event', 'video'])],
            'item_id' => ['required', 'integer', 'min:1'],
        ]);

        $viewableType = match ($validated['type']) {
            'creator' => 'discovery_creator',
            'event' => 'discovery_event',
            'video' => 'discovery_video',
        };

        $this->contentViewStateService->recordView(
            viewerId: (int) $request->user()->id,
            viewableType: $viewableType,
            viewableId: (int) $validated['item_id']
        );

        return response()->json([
            'message' => 'Discovery item view recorded successfully.',
            'meta' => [
                'type' => $validated['type'],
                'item_id' => (int) $validated['item_id'],
            ],
        ]);
    }

    public function hashtags(Request $request)
    {
        $validated = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = mb_strtolower(ltrim(trim((string) ($validated['query'] ?? '')), '#'));
        $limit = (int) ($validated['limit'] ?? 30);
        $counts = collect();

        Video::query()
            ->where('status', 'published')
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->latest('id')
            ->limit(500)
            ->get()
            ->each(function (Video $video) use ($counts): void {
                foreach ((array) data_get($video->metadata, 'caption_hashtags', []) as $tag) {
                    $normalized = mb_strtolower(ltrim(trim((string) $tag), '#'));
                    if ($normalized !== '') $counts->put($normalized, (int) $counts->get($normalized, 0) + 1);
                }
            });

        CommunityPost::query()
            ->where('status', 'published')
            ->where('audience', 'public')
            ->whereNotNull('hashtags')
            ->select(['id', 'hashtags'])
            ->latest('id')
            ->limit(500)
            ->get()
            ->each(function (CommunityPost $post) use ($counts): void {
                foreach ((array) $post->hashtags as $tag) {
                    $normalized = mb_strtolower(ltrim(trim((string) $tag), '#'));
                    if ($normalized !== '') $counts->put($normalized, (int) $counts->get($normalized, 0) + 1);
                }
            });

        $hashtags = $counts
            ->filter(fn (int $count, string $tag) => $query === '' || str_contains($tag, $query))
            ->map(fn (int $count, string $tag) => ['tag' => $tag, 'posts_count' => $count])
            ->sortByDesc('posts_count')
            ->take($limit)
            ->values();

        return response()->json(['data' => $hashtags]);
    }
}
