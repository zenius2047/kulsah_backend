<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\VideoService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class VideoController extends Controller
{
    public function __construct(private readonly VideoService $videoService)
    {
    }

    public function store(Request $request)
    {
        $this->normalizeContentTypes($request);

        $validated = $request->validate([
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
            $video = $this->videoService->uploadVideo(
                data: [
                    'title' => $validated['title'] ?? null,
                    'caption' => $validated['caption'] ?? null,
                    'content_type' => $validated['content_type'][0] ?? null,
                    'content_types' => $validated['content_type'] ?? [],
                    'visibility' => $validated['visibility'] ?? 'public',
                    'original_name' => $request->file('video')->getClientOriginalName(),
                    'mime_type' => $request->file('video')->getMimeType(),
                    'size' => $request->file('video')->getSize(),
                ],
                file: $request->file('video'),
                userId: (int) $request->user()->id,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to upload video.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to upload video.',
                'error' => $throwable->getMessage(),
            ], 500);
        }

        $video->refresh();

        return response()->json([
            'message' => 'Video uploaded successfully and is being processed.',
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

        return response()->json([
            'data' => new VideoResource($video),
        ]);
    }

    public function progress(Request $request, Video $video)
    {
        return response()->json([
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
}
