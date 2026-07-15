<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorVideoResource;
use App\Http\Resources\CreatorVideoDetailResource;
use App\Http\Resources\VideoPlaylistResource;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Models\VideoPlaylist;
use App\Models\VideoView;
use App\Services\VideoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class VideoController extends Controller
{
    public function __construct(private readonly VideoService $videoService)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category' => ['sometimes', 'string', 'max:120'],
        ]);
        $draft = $this->parseBooleanQuery($request, 'draft');
        $premium = $this->parseBooleanQuery($request, 'premium');

        $videos = Video::query()
            ->withCount('likes')
            ->where('user_id', $request->user()->id)
            ->when(
                $draft !== null,
                fn ($query) => $draft
                    ? $query->where('status', '!=', 'ready')
                    : $query->where('status', 'ready')
            )
            ->when(
                $premium !== null,
                fn ($query) => $premium
                    ? $query->where('visibility', 'premium')
                    : $query->where('visibility', '!=', 'premium')
            )
            ->when(
                isset($validated['category']) && $validated['category'] !== '',
                fn ($query) => $query->where(function ($query) use ($validated): void {
                    $query->where('content_type', $validated['category'])
                        ->orWhereJsonContains('content_types', $validated['category']);
                })
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => CreatorVideoResource::collection($videos),
            'meta' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
            ],
        ]);
    }

    public function analytics(Request $request)
    {
        $videos = Video::query()
            ->where('user_id', $request->user()->id)
            ->withCount(['likes', 'comments'])
            ->get();

        $summary = [
            'total_videos' => $videos->count(),
            'ready_videos' => $videos->where('status', 'ready')->count(),
            'draft_videos' => $videos->where('status', '!=', 'ready')->count(),
            'premium_videos' => $videos->where('visibility', 'premium')->count(),
            'public_videos' => $videos->where('visibility', 'public')->count(),
            'processing_videos' => $videos->where('status', 'processing')->count(),
            'failed_videos' => $videos->where('status', 'failed')->count(),
            'total_views' => (int) $videos->sum('views_count'),
            'total_likes' => (int) $videos->sum('likes_count'),
            'total_comments' => (int) $videos->sum('comments_count'),
            'total_duration_seconds' => (int) $videos->sum('duration'),
            'average_views' => $videos->count() > 0 ? round(((int) $videos->sum('views_count')) / $videos->count(), 2) : 0,
        ];

        $summary['total_duration'] = $this->formatDuration($summary['total_duration_seconds']);

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function creatorShow(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to view this video.'
        );

        $video->load([
            'user:id,name,username,avatar,banner',
            'playlists:id,name',
            'comments' => $this->creatorVideoCommentsLoad(),
        ]);
        $video->loadCount(['likes', 'comments']);
        $video->setRelation(
            'otherVideos',
            Video::query()
                ->with('user:id,name,username,avatar,banner')
                ->with([
                    'comments' => $this->creatorVideoCommentsLoad(),
                ])
                ->withCount(['likes', 'comments'])
                ->where('user_id', $request->user()->id)
                ->whereKeyNot($video->id)
                ->latest()
                ->limit(6)
                ->get()
        );

        return response()->json([
            'item' => new CreatorVideoDetailResource($video),
        ]);
    }

    private function creatorVideoCommentsLoad(): \Closure
    {
        return function ($query): void {
            $query->whereNull('parent_id')
                ->latest()
                ->with([
                    'user:id,name,username,avatar,banner,verified',
                    'replies' => function ($replyQuery): void {
                        $replyQuery->oldest()->with('user:id,name,username,avatar,banner,verified')->withCount('likes');
                    },
                ])
                ->withCount('likes');
        };
    }

    public function store(Request $request)
    {
        $this->normalizeContentTypes($request);

        $request->validate([
            'video' => [
                'required',
                'file',
                'mimetypes:'.implode(',', config('video.allowed_mimetypes', [])),
                'max:'.config('video.max_upload_kb', 102400),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'content_type' => ['sometimes', 'array', 'min:1'],
            'content_type.*' => ['required', 'string', 'max:120', 'distinct'],
            'visibility' => ['sometimes', 'string', 'in:public,premium'],
        ]);

        try {
            $contentTypes = $this->resolveContentTypes($request);

            $video = $this->videoService->uploadVideo(
                data: [
                    'title' => $request->input('title'),
                    'caption' => $request->input('caption'),
                    'content_type' => $contentTypes[0] ?? null,
                    'content_types' => $contentTypes,
                    'visibility' => $request->input('visibility', 'public'),
                    'original_name' => $request->file('video')->getClientOriginalName(),
                    'mime_type' => $request->file('video')->getMimeType(),
                    'size' => $request->file('video')->getSize(),
                ],
                file: $request->file('video'),
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);
            Log::error('Video upload failed.', $this->uploadErrorContext($throwable, 'store', $request));

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to upload video.',
                    'errors' => $throwable->errors(),
                    'stage' => 'validation',
                    'debug' => $this->debugPayload($throwable, 'store'),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to upload video.',
                'error' => $throwable->getMessage(),
                'stage' => 'storage_or_processing',
                'debug' => $this->debugPayload($throwable, 'store'),
            ], 500);
        }

        $video->refresh();

        return response()->json([
            'message' => 'Video uploaded successfully and is now in draft while processing starts.',
            'data' => new VideoResource($video),
        ], 201);
    }

    public function storePlaylist(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $playlist = VideoPlaylist::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Video playlist created successfully.',
            'data' => new VideoPlaylistResource($playlist),
        ], 201);
    }

    public function playlists(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $playlists = VideoPlaylist::query()
            ->where('user_id', $request->user()->id)
            ->withCount('videos')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => VideoPlaylistResource::collection($playlists),
            'meta' => [
                'current_page' => $playlists->currentPage(),
                'last_page' => $playlists->lastPage(),
                'per_page' => $playlists->perPage(),
                'total' => $playlists->total(),
            ],
        ]);
    }

    public function showPlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $playlist->load([
            'videos' => function ($query): void {
                $query->orderByDesc('videos.id')
                    ->withCount(['likes', 'comments'])
                    ->with(['playlists:id,name']);
            },
        ])->loadCount('videos');

        return response()->json([
            'data' => new VideoPlaylistResource($playlist),
        ]);
    }

    public function playlistVideos(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $videos = $playlist->videos()
            ->withCount(['likes', 'comments'])
            ->with('playlists:id,name')
            ->orderByDesc('videos.id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => VideoResource::collection($videos),
            'meta' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
            ],
        ]);
    }

    public function updatePlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $playlist->update($validated);

        $playlist->loadCount('videos');

        return response()->json([
            'message' => 'Video playlist updated successfully.',
            'data' => new VideoPlaylistResource($playlist),
        ]);
    }

    public function destroyPlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $playlist->delete();

        return response()->json([
            'message' => 'Video playlist deleted successfully.',
        ]);
    }

    public function moveToPlaylist(Request $request, VideoPlaylist $playlist, Video $video)
    {
        $this->authorizePlaylistAndVideo($request, $playlist, $video);

        $video->playlists()->syncWithoutDetaching([$playlist->id]);

        $video->load('playlists:id,name');

        return response()->json([
            'message' => 'Video added to playlist successfully.',
            'data' => new VideoResource($video),
        ]);
    }

    public function moveManyToPlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $validated = $request->validate([
            'video_ids' => ['required', 'array', 'min:1', 'max:100'],
            'video_ids.*' => ['required', 'integer', 'distinct', 'exists:videos,id'],
        ]);

        $videoIds = array_values(array_unique(array_map('intval', $validated['video_ids'])));

        $videos = Video::query()
            ->whereKey($videoIds)
            ->where('user_id', $request->user()->id)
            ->get();

        if ($videos->count() !== count($videoIds)) {
            abort(403, 'You are not allowed to modify one or more of these videos.');
        }

        DB::transaction(function () use ($playlist, $videos): void {
            $videos->each(function (Video $video) use ($playlist): void {
                $video->playlists()->syncWithoutDetaching([$playlist->id]);
            });
        });

        $videos->load('playlists:id,name');

        return response()->json([
            'message' => 'Videos added to playlist successfully.',
            'data' => VideoResource::collection($videos),
        ]);
    }

    public function removeFromPlaylist(Request $request, VideoPlaylist $playlist, Video $video)
    {
        $this->authorizePlaylistAndVideo($request, $playlist, $video);

        if (! $video->playlists()->whereKey($playlist->id)->exists()) {
            return response()->json([
                'message' => 'Video does not belong to this playlist.',
            ], 409);
        }

        $video->playlists()->detach($playlist->id);

        $video->load('playlists:id,name');

        return response()->json([
            'message' => 'Video removed from playlist successfully.',
            'data' => new VideoResource($video),
        ]);
    }

    public function draft(Request $request)
    {
        $this->normalizeContentTypes($request);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'content_type' => ['sometimes', 'array', 'min:1'],
            'content_type.*' => ['required', 'string', 'max:120', 'distinct'],
            'visibility' => ['sometimes', 'string', 'in:public,premium'],
        ]);

        $contentTypes = $this->resolveContentTypes($request);

        $video = $this->videoService->createDraftVideo(
            data: [
                'title' => $request->input('title'),
                'caption' => $request->input('caption'),
                'content_type' => $contentTypes[0] ?? null,
                'content_types' => $contentTypes,
                'visibility' => $request->input('visibility', 'public'),
            ],
            userId: (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Video draft created successfully.',
            'data' => new VideoResource($video),
        ], 201);
    }

    public function upload(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to update this video.'
        );

        $validated = $request->validate([
            'video' => [
                'required',
                'file',
                'mimetypes:'.implode(',', config('video.allowed_mimetypes', [])),
                'max:'.config('video.max_upload_kb', 102400),
            ],
        ]);

        try {
            $video = $this->videoService->attachUploadedVideo(
                video: $video,
                file: $request->file('video'),
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);
            Log::error('Video re-upload failed.', $this->uploadErrorContext($throwable, 'upload', $request, $video));

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to upload video.',
                    'errors' => $throwable->errors(),
                    'stage' => 'validation',
                    'debug' => $this->debugPayload($throwable, 'upload', $video->id),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to upload video.',
                'error' => $throwable->getMessage(),
                'stage' => 'storage_or_processing',
                'debug' => $this->debugPayload($throwable, 'upload', $video->id),
            ], 500);
        }

        return response()->json([
            'message' => 'Video uploaded successfully and is now in draft while processing starts.',
            'data' => new VideoResource($video),
        ], 201);
    }

    public function update(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to update this video.'
        );

        $this->normalizeContentTypes($request);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'content_type' => ['sometimes', 'array', 'min:1'],
            'content_type.*' => ['required', 'string', 'max:120', 'distinct'],
            'visibility' => ['sometimes', 'string', 'in:public,premium'],
        ]);

        try {
            $video = $this->videoService->updateVideo(
                video: $video,
                data: $validated,
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to update video.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to update video.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Video updated successfully.',
            'data' => new VideoResource($video),
        ]);
    }

    public function show(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id
                || $request->user()->roles()->whereIn('name', ['admin'])->exists(),
            403,
            'You are not allowed to view this video.'
        );

        $video->load('playlists:id,name');

        return response()->json([
            'data' => new VideoResource($video),
        ]);
    }

    public function progress(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to view this video.'
        );

        return response()->json([
            'data' => [
                'video_id' => $video->id,
                'status' => $video->status,
                'progress_percentage' => (int) ($video->progress_percentage ?? 0),
            ],
        ]);
    }

    public function updateProgress(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to update this video.'
        );

        $validated = $request->validate([
            'progress_percentage' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $video = $this->videoService->updateUploadProgress(
            video: $video,
            progressPercentage: (int) $validated['progress_percentage'],
        );

        return response()->json([
            'message' => 'Video upload progress updated successfully.',
            'data' => [
                'video_id' => $video->id,
                'status' => $video->status,
                'progress_percentage' => (int) ($video->progress_percentage ?? 0),
            ],
        ]);
    }

    public function view(Request $request, Video $video)
    {
        $updated = $this->videoService->recordView($video, (int) $request->user()->id);

        return response()->json([
            'message' => 'Video view recorded successfully.',
            'data' => new VideoResource($updated),
        ]);
    }

    public function watched(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $watchedVideos = Video::query()
            ->joinSub(
                VideoView::query()
                    ->selectRaw('video_id, MAX(viewed_at) as last_watched_at')
                    ->where('user_id', $request->user()->id)
                    ->groupBy('video_id'),
                'watched_videos',
                'watched_videos.video_id',
                '=',
                'videos.id'
            )
            ->select('videos.*', 'watched_videos.last_watched_at')
            ->with('user:id,name,username,avatar,banner')
            ->orderByDesc('watched_videos.last_watched_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $videos = $watchedVideos->getCollection()->map(function (Video $video) {
            return [
                'id' => (string) $video->id,
                'title' => (string) ($video->title ?: $video->caption ?: ''),
                'views' => $this->formatCount($video->views_count ?? 0),
                'duration' => $this->formatDuration((int) ($video->duration ?? 0)),
                'img' => $video->thumbnail_url ?: $video->cdn_url ?: data_get($video->metadata ?? [], 'thumbnail'),
                'watched_at' => $video->last_watched_at
                    ? Carbon::parse($video->last_watched_at)->toIso8601String()
                    : null,
            ];
        })->values();

        return response()->json([
            'data' => [
                'videos' => $videos,
            ],
            'meta' => [
                'current_page' => $watchedVideos->currentPage(),
                'last_page' => $watchedVideos->lastPage(),
                'per_page' => $watchedVideos->perPage(),
                'total' => $watchedVideos->total(),
            ],
        ]);
    }

    private function normalizeContentTypes(Request $request): void
    {
        $contentTypesInput = $request->input('content_type', $request->input('content_types'));

        if ($contentTypesInput === null) {
            return;
        }

        $contentTypes = is_array($contentTypesInput)
            ? $contentTypesInput
            : preg_split('/\s*,\s*/', trim((string) $contentTypesInput), -1, PREG_SPLIT_NO_EMPTY);

        $request->merge([
            'content_type' => array_values(array_filter(array_map(
                fn ($value) => is_string($value) ? trim($value) : '',
                $contentTypes
            ))),
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
            : sprintf('%02d:%02d', $minutes, $remainingSeconds);
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
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'k';
        }

        return (string) (int) $value;
    }

    private function parseBooleanQuery(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }

        $value = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($value) ? $value : null;
    }

    private function resolveContentTypes(Request $request): array
    {
        $contentTypesInput = $request->input('content_type', $request->input('content_types', []));

        if (! is_array($contentTypesInput)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            $contentTypesInput
        )));
    }

    private function debugPayload(Throwable $throwable, string $stage, int|string|null $videoId = null): array
    {
        if (! config('app.debug')) {
            return [];
        }

        return array_filter([
            'stage' => $stage,
            'video_id' => $videoId,
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function uploadErrorContext(Throwable $throwable, string $stage, Request $request, ?Video $video = null): array
    {
        return array_filter([
            'stage' => $stage,
            'user_id' => $request->user()?->id,
            'video_id' => $video?->id,
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function authorizePlaylist(Request $request, VideoPlaylist $playlist): void
    {
        abort_unless(
            (string) $playlist->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to modify this playlist.'
        );
    }

    private function authorizePlaylistAndVideo(Request $request, VideoPlaylist $playlist, Video $video): void
    {
        abort_unless(
            (string) $playlist->user_id === (string) $request->user()->id
                && (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to modify this playlist.'
        );
    }
}
