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
        $validated = $request->validate([
            'video' => [
                'required',
                'file',
                'mimetypes:'.implode(',', config('video.allowed_mimetypes', [])),
                'max:'.config('video.max_upload_kb', 102400),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['required', 'string', 'in:public,premium'],
        ]);

        try {
            $video = $this->videoService->uploadVideo(
                data: [
                    'title' => $validated['title'] ?? null,
                    'caption' => $validated['caption'] ?? null,
                    'visibility' => $validated['visibility'],
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
}
