<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Video\VideoRenderRequest;
use App\Http\Resources\CreatorVideoDetailResource;
use App\Http\Resources\CreatorVideoResource;
use App\Http\Resources\VideoPlaylistResource;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Models\VideoPlaylist;
use App\Models\VideoView;
use App\Services\VideoCacheService;
use App\Services\VideoEditService;
use App\Services\VideoService;
use App\Services\VideoStorageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class VideoController extends Controller
{
    public function __construct(
        private readonly VideoService $videoService,
        private readonly VideoCacheService $videoCacheService,
        private readonly VideoStorageService $videoStorageService,
        private readonly VideoEditService $videoEditService,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category' => ['sometimes', 'string', 'max:120'],
        ]);
        $draft = $this->parseBooleanQuery($request, 'draft');
        $premium = $this->parseBooleanQuery($request, 'premium');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'videos:index',
            context: [
                'page' => $page,
                'per_page' => $perPage,
                'draft' => $draft,
                'premium' => $premium,
                'category' => $validated['category'] ?? null,
            ],
            resolver: function () use ($request, $validated, $draft, $premium, $page, $perPage): array {
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
                    ->paginate($perPage, ['*'], 'page', $page);

                return [
                    'data' => CreatorVideoResource::collection($videos)->resolve($request),
                    'meta' => [
                        'current_page' => $videos->currentPage(),
                        'last_page' => $videos->lastPage(),
                        'per_page' => $videos->perPage(),
                        'total' => $videos->total(),
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function analytics(Request $request)
    {
        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'videos:analytics',
            context: [],
            resolver: function () use ($request): array {
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

                return [
                    'data' => $summary,
                ];
            }
        );

        return response()->json($payload);
    }

    public function creatorShow(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to view this video.'
        );

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'videos:creator-show',
            context: ['video_id' => (int) $video->id],
            resolver: function () use ($request, $video): array {
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

                return [
                    'item' => (new CreatorVideoDetailResource($video))->resolve($request),
                ];
            }
        );

        return response()->json($payload);
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
            'thumbnail' => $this->thumbnailValidationRules(),
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
                thumbnailFile: $request->file('thumbnail'),
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
        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Video uploaded successfully and is now in draft while processing starts.',
            'data' => new VideoResource($video),
        ], 201);
    }

    public function initFastUpload(Request $request)
    {
        $this->normalizeContentTypes($request);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'content_type' => ['sometimes', 'array', 'min:1'],
            'content_type.*' => ['required', 'string', 'max:120', 'distinct'],
            'visibility' => ['sometimes', 'string', 'in:public,premium'],
            'thumbnail' => $this->thumbnailValidationRules(),
            'original_name' => ['nullable', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'integer', 'min:0'],
            'requires_editing' => ['sometimes', 'boolean'],
        ]);

        $contentTypes = $this->resolveContentTypes($request);

        try {
            $session = $this->videoService->createDirectUploadSession(
                data: [
                    'title' => $request->input('title'),
                    'caption' => $request->input('caption'),
                    'content_type' => $contentTypes[0] ?? null,
                    'content_types' => $contentTypes,
                    'visibility' => $request->input('visibility', 'public'),
                    'original_name' => $request->input('original_name'),
                    'mime_type' => $request->input('mime_type'),
                    'size' => $request->input('size'),
                    'requires_editing' => $request->boolean('requires_editing'),
                ],
                userId: (int) $request->user()->id,
                thumbnailFile: $request->file('thumbnail'),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to create direct upload session.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to create direct upload session.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Direct upload session created successfully.',
            'data' => [
                'video' => new VideoResource($session['video']),
                'upload' => $session['upload'],
            ],
        ], 201);
    }

    public function completeFastUpload(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to update this video.'
        );

        try {
            $video = $this->videoService->finalizeDirectUpload(
                video: $video,
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to complete upload.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to complete upload.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        $this->invalidateCreatorCaches((int) $request->user()->id);

        $requiresEditing = (bool) data_get($video->metadata, 'requires_editing', false);

        return response()->json([
            'message' => $requiresEditing
                ? 'Direct upload completed successfully and is ready for editing.'
                : 'Direct upload completed successfully and processing has started.',
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

        $this->invalidateCreatorCaches((int) $request->user()->id);

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
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'playlists:index',
            context: [
                'page' => $page,
                'per_page' => $perPage,
            ],
            resolver: function () use ($request, $page, $perPage): array {
                $playlists = VideoPlaylist::query()
                    ->where('user_id', $request->user()->id)
                    ->with([
                        'videos' => function ($query): void {
                            $query->orderBy('video_playlist_video.created_at')
                                ->with(['user:id,name,username,avatar,banner'])
                                ->withCount(['likes', 'comments']);
                        },
                    ])
                    ->withCount('videos')
                    ->latest('id')
                    ->paginate($perPage, ['*'], 'page', $page);

                return [
                    'data' => VideoPlaylistResource::collection($playlists)->resolve($request),
                    'meta' => [
                        'current_page' => $playlists->currentPage(),
                        'last_page' => $playlists->lastPage(),
                        'per_page' => $playlists->perPage(),
                        'total' => $playlists->total(),
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function showPlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'playlists:show',
            context: ['playlist_id' => (int) $playlist->id],
            resolver: function () use ($request, $playlist): array {
                $playlist->load([
                    'videos' => function ($query): void {
                        $query->orderByDesc('videos.id')
                            ->withCount(['likes', 'comments'])
                            ->with(['playlists:id,name']);
                    },
                ])->loadCount('videos');

                return [
                    'data' => (new VideoPlaylistResource($playlist))->resolve($request),
                ];
            }
        );

        return response()->json($payload);
    }

    public function playlistVideos(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'playlists:videos',
            context: ['playlist_id' => (int) $playlist->id],
            resolver: function () use ($request, $playlist): array {
                $videos = $playlist->videos()
                    ->with([
                        'user:id,name,username,avatar,banner',
                        'comments' => $this->creatorVideoCommentsLoad(),
                    ])
                    ->withCount(['likes', 'comments'])
                    ->orderByDesc('videos.id')
                    ->get();

                $currentVideo = $videos->first();
                $nextVideos = $videos->slice(1)->values();

                return [
                    'playlist_id' => (string) $playlist->id,
                    'playlist_name' => (string) $playlist->name,
                    'background' => $playlist->background,
                    'item' => $currentVideo
                        ? (new CreatorVideoDetailResource($currentVideo))->resolve($request)
                        : null,
                    'next_videos' => CreatorVideoResource::collection($nextVideos)->resolve($request),
                ];
            }
        );

        return response()->json($payload);
    }

    public function updatePlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $playlist->update($validated);

        $playlist->loadCount('videos');
        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Video playlist updated successfully.',
            'data' => new VideoPlaylistResource($playlist),
        ]);
    }

    public function destroyPlaylist(Request $request, VideoPlaylist $playlist)
    {
        $this->authorizePlaylist($request, $playlist);

        $playlist->delete();
        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Video playlist deleted successfully.',
        ]);
    }

    public function moveToPlaylist(Request $request, VideoPlaylist $playlist, Video $video)
    {
        $this->authorizePlaylistAndVideo($request, $playlist, $video);

        $video->playlists()->syncWithoutDetaching([$playlist->id]);

        $video->load('playlists:id,name');
        $this->invalidateCreatorCaches((int) $request->user()->id);

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
        $this->invalidateCreatorCaches((int) $request->user()->id);

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
        $this->invalidateCreatorCaches((int) $request->user()->id);

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
            'thumbnail' => $this->thumbnailValidationRules(),
        ]);

        $contentTypes = $this->resolveContentTypes($request);

        $thumbnail = null;

        try {
            $thumbnail = $this->storeThumbnailIfProvided($request->file('thumbnail'), (int) $request->user()->id);

            $video = $this->videoService->createDraftVideo(
                data: [
                    'title' => $request->input('title'),
                    'caption' => $request->input('caption'),
                    'content_type' => $contentTypes[0] ?? null,
                    'content_types' => $contentTypes,
                    'visibility' => $request->input('visibility', 'public'),
                    'thumbnail_url' => $thumbnail['source_url'] ?? null,
                ],
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            if ($thumbnail) {
                $this->videoStorageService->delete($thumbnail['source_key'], $thumbnail['disk']);
            }

            throw $throwable;
        }

        $this->invalidateCreatorCaches((int) $request->user()->id);

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
            'thumbnail' => $this->thumbnailValidationRules(),
        ]);

        try {
            $video = $this->videoService->attachUploadedVideo(
                video: $video,
                file: $request->file('video'),
                userId: (int) $request->user()->id,
                thumbnailFile: $request->file('thumbnail'),
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

        $this->invalidateCreatorCaches((int) $request->user()->id);

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

        $this->invalidateCreatorCaches((int) $request->user()->id);

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

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $video->user_id,
            scope: 'videos:show',
            context: ['video_id' => (int) $video->id],
            resolver: function () use ($request, $video): array {
                $video->load('playlists:id,name');

                return [
                    'data' => (new VideoResource($video))->resolve($request),
                ];
            }
        );

        return response()->json($payload);
    }

    public function progress(Request $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to view this video.'
        );

        $payload = $this->videoCacheService->rememberCreator(
            creatorId: (int) $request->user()->id,
            scope: 'videos:progress',
            context: ['video_id' => (int) $video->id],
            resolver: function () use ($video): array {
                $video->refresh();

                return [
                    'data' => [
                        'video_id' => $video->id,
                        'status' => $video->status,
                        'render_status' => $video->render_status,
                        'progress_percentage' => (int) ($video->progress_percentage ?? 0),
                        'requires_editing' => (bool) data_get($video->metadata, 'requires_editing', false),
                        'upload_state' => data_get($video->metadata, 'upload_state'),
                        'processing_state' => data_get($video->metadata, 'processing_state'),
                    ],
                ];
            }
        );

        return response()->json($payload);
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

        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Video upload progress updated successfully.',
            'data' => [
                'video_id' => $video->id,
                'status' => $video->status,
                'render_status' => $video->render_status,
                'progress_percentage' => (int) ($video->progress_percentage ?? 0),
                'requires_editing' => (bool) data_get($video->metadata, 'requires_editing', false),
                'upload_state' => data_get($video->metadata, 'upload_state'),
                'processing_state' => data_get($video->metadata, 'processing_state'),
            ],
        ]);
    }

    public function edit(VideoRenderRequest $request, Video $video)
    {
        abort_unless(
            (string) $video->user_id === (string) $request->user()->id,
            403,
            'You are not allowed to edit this video.'
        );

        $rawSchemaVersion = $request->input('schemaVersion');
        $rawMetadata = $request->input('metadata');
        $rawCanvas = $request->input('canvas', []);
        $rawOutput = $request->input('output', []);
        $rawAssets = $request->input('assets', []);
        $rawScenes = $request->input('scenes', []);
        $rawFilters = $request->input('filters', []);
        $rawAudio = $request->input('audio', []);
        $rawTrim = $request->input('trim', []);
        $rawGlobalAudioTracks = $request->input('globalAudioTracks');
        $rawGlobalEffects = $request->input('globalEffects');
        $rawGuides = $request->input('guides');
        $editAssetUploads = $this->storeEditAssetUploads($request->file('asset_files', []), (int) $request->user()->id);

        if (is_array($rawAssets) && $rawAssets !== []) {
            $rawAssets = $this->applyEditAssetUploadsToAssets($rawAssets, $editAssetUploads, (int) $request->user()->id);
        }

        try {
            $timelinePayload = [
                'video_id' => (int) $video->id,
                'schemaVersion' => is_string($rawSchemaVersion) && $rawSchemaVersion !== ''
                    ? $rawSchemaVersion
                    : '3.0.0',
                'metadata' => is_array($rawMetadata) ? $rawMetadata : [],
                'canvas' => is_array($rawCanvas) ? $rawCanvas : [],
                'output' => is_array($rawOutput) ? $rawOutput : [],
                'assets' => is_array($rawAssets) ? $rawAssets : [],
                'scenes' => is_array($rawScenes) ? $rawScenes : [],
                'globalAudioTracks' => is_array($rawGlobalAudioTracks) ? $rawGlobalAudioTracks : [],
                'globalEffects' => is_array($rawGlobalEffects) ? $rawGlobalEffects : [],
                'guides' => is_array($rawGuides) ? $rawGuides : [],
                'filters' => is_array($rawFilters) ? $rawFilters : [],
                'audio' => is_array($rawAudio) ? $rawAudio : [],
                'trim' => is_array($rawTrim) ? $rawTrim : [],
            ];

            $video = $this->videoEditService->queueTimelineRender(
                video: $video,
                timeline: $timelinePayload,
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to edit video.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to edit video.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        $this->invalidateCreatorCaches((int) $request->user()->id);

        return response()->json([
            'message' => 'Video edit queued successfully.',
            'data' => new VideoResource($video),
        ], 202);
    }

    public function view(Request $request, Video $video)
    {
        $updated = $this->videoService->recordView($video, (int) $request->user()->id);
        $this->videoCacheService->invalidateViewer((int) $request->user()->id);

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
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $payload = $this->videoCacheService->rememberViewer(
            viewerId: (int) $request->user()->id,
            scope: 'videos:watched',
            context: [
                'page' => $page,
                'per_page' => $perPage,
            ],
            resolver: function () use ($request, $page, $perPage): array {
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
                    ->paginate($perPage, ['*'], 'page', $page);

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

                return [
                    'data' => [
                        'videos' => $videos,
                    ],
                    'meta' => [
                        'current_page' => $watchedVideos->currentPage(),
                        'last_page' => $watchedVideos->lastPage(),
                        'per_page' => $watchedVideos->perPage(),
                        'total' => $watchedVideos->total(),
                    ],
                ];
            }
        );

        return response()->json($payload);
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

    private function normalizeOverlays(Request $request): void
    {
        $overlays = $request->input('overlays');

        if (! is_string($overlays)) {
            return;
        }

        $decoded = json_decode($overlays, true);

        if (is_array($decoded)) {
            $request->merge([
                'overlays' => $decoded,
            ]);
        }
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

    private function thumbnailValidationRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:'.(int) config('video.thumbnail_max_upload_kb', 5120),
        ];
    }

    /**
     * @param  array<int, UploadedFile>|UploadedFile|mixed  $files
     * @return array<int, array{disk:string,source_key:string,source_url:string}>
     */
    private function storeEditAssetUploads(mixed $files, int $userId): array
    {
        $normalizedFiles = is_array($files)
            ? array_values($files)
            : ($files instanceof UploadedFile ? [$files] : []);

        $uploads = [];

        foreach ($normalizedFiles as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $uploads[(int) $index] = $this->videoStorageService->uploadEditAsset($file, $userId);
        }

        return $uploads;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @param  array<int, array{disk:string,source_key:string,source_url:string}>  $uploads
     * @return array<int, array<string, mixed>>
     */
    private function applyEditAssetUploadsToAssets(array $assets, array $uploads, int $userId): array
    {
        return array_map(function ($asset, $index) use ($uploads, $userId) {
            if (! is_array($asset)) {
                return $asset;
            }

            $fileIndex = $this->resolveEditAssetFileIndex($asset, $index);

            if ($fileIndex !== null && isset($uploads[$fileIndex])) {
                $upload = $uploads[$fileIndex];
                $asset = array_merge($asset, [
                    'storageProvider' => $upload['disk'],
                    'storageKey' => $upload['source_key'],
                    'url' => $upload['source_url'],
                    'asset_url' => $upload['source_url'],
                    'asset_disk' => $upload['disk'],
                    'asset_key' => $upload['source_key'],
                ]);
            }

            foreach (['url', 'asset_url', 'fallbackUrl', 'fallback_url'] as $field) {
                if (! isset($asset[$field]) || ! is_string($asset[$field])) {
                    continue;
                }

                $inlineUpload = $this->storeInlineImageIfNeeded($asset[$field], $userId);

                if ($inlineUpload === null) {
                    continue;
                }

                $asset = array_merge($asset, [
                    'storageProvider' => $inlineUpload['disk'],
                    'storageKey' => $inlineUpload['source_key'],
                    'url' => $inlineUpload['source_url'],
                    'asset_url' => $inlineUpload['source_url'],
                    'asset_disk' => $inlineUpload['disk'],
                    'asset_key' => $inlineUpload['source_key'],
                ]);

                break;
            }

            return $asset;
        }, $assets, array_keys($assets));
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<int, array{disk:string,source_key:string,source_url:string}>  $uploads
     * @return array<string, mixed>
     */
    private function applyEditAssetUploadsToProject(array $project, array $uploads, int $userId): array
    {
        if (isset($project['assets']) && is_array($project['assets'])) {
            $project['assets'] = $this->applyEditAssetUploadsToAssets($project['assets'], $uploads, $userId);
        }

        return $project;
    }

    /**
     * @param  array<int, array<string, mixed>>  $layers
     * @param  array<int, array{disk:string,source_key:string,source_url:string}>  $uploads
     * @return array<int, array<string, mixed>>
     */
    private function applyEditAssetUploadsToMediaLayers(array $layers, array $uploads, int $userId): array
    {
        return array_map(function ($layer, $index) use ($uploads, $userId) {
            if (! is_array($layer)) {
                return $layer;
            }

            $fileIndex = $this->resolveEditAssetFileIndex($layer, $index);

            if ($fileIndex !== null && isset($uploads[$fileIndex])) {
                $upload = $uploads[$fileIndex];
                $layer = array_merge($layer, [
                    'asset_url' => $upload['source_url'],
                    'asset_disk' => $upload['disk'],
                    'asset_key' => $upload['source_key'],
                ]);
            }

            foreach (['asset_url', 'url'] as $field) {
                if (! isset($layer[$field]) || ! is_string($layer[$field])) {
                    continue;
                }

                $inlineUpload = $this->storeInlineImageIfNeeded($layer[$field], $userId);

                if ($inlineUpload === null) {
                    continue;
                }

                $layer = array_merge($layer, [
                    'asset_url' => $inlineUpload['source_url'],
                    'asset_disk' => $inlineUpload['disk'],
                    'asset_key' => $inlineUpload['source_key'],
                ]);
                break;
            }

            return $layer;
        }, $layers, array_keys($layers));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveEditAssetFileIndex(array $item, mixed $fallbackIndex): ?int
    {
        foreach (['asset_file_index', 'file_index'] as $field) {
            if (! array_key_exists($field, $item) || ! is_numeric($item[$field])) {
                continue;
            }

            return (int) $item[$field];
        }

        return is_numeric($fallbackIndex) ? (int) $fallbackIndex : null;
    }

    private function storeInlineImageIfNeeded(string $value, int $userId): ?array
    {
        if (! str_starts_with($value, 'data:image/')) {
            return null;
        }

        if (! preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/s', $value, $matches)) {
            throw ValidationException::withMessages([
                'asset_url' => 'Inline image data must use a valid base64 data URL.',
            ]);
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false) {
            throw ValidationException::withMessages([
                'asset_url' => 'Inline image data could not be decoded.',
            ]);
        }

        $mimeType = $matches[1];
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'kulsah-edit-asset-');

        if ($tempPath === false) {
            throw ValidationException::withMessages([
                'asset_url' => 'Unable to prepare inline image upload.',
            ]);
        }

        $tempFile = $tempPath.'.'.$extension;
        @unlink($tempPath);
        file_put_contents($tempFile, $binary);
        $uploadFile = new UploadedFile($tempFile, 'inline-image.'.$extension, $mimeType, null, true);

        try {
            return $this->videoStorageService->uploadEditAsset($uploadFile, $userId);
        } finally {
            @unlink($tempFile);
        }
    }

    private function storeThumbnailIfProvided(?UploadedFile $thumbnailFile, int $userId): ?array
    {
        if (! $thumbnailFile) {
            return null;
        }

        return $this->videoStorageService->uploadThumbnail($thumbnailFile, $userId);
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

    private function invalidateCreatorCaches(int $creatorId): void
    {
        $this->videoCacheService->invalidateCreator($creatorId);
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
