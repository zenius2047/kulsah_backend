<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoCommentResource;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoComment;
use App\Services\SocialEngagementService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialController extends Controller
{
    public function __construct(private readonly SocialEngagementService $socialEngagementService)
    {
    }

    public function like(Request $request, string $video)
    {
        return $this->handle(function () use ($request, $video) {
            return $this->socialEngagementService->likeVideo(
                $request->user(),
                Video::query()->findOrFail($video)
            );
        });
    }

    public function unlike(Request $request, string $video)
    {
        return $this->handle(function () use ($request, $video) {
            return $this->socialEngagementService->unlikeVideo(
                $request->user(),
                Video::query()->findOrFail($video)
            );
        });
    }

    public function bookmark(Request $request, string $video)
    {
        return $this->handle(function () use ($request, $video) {
            return $this->socialEngagementService->bookmarkVideo(
                $request->user(),
                Video::query()->findOrFail($video)
            );
        });
    }

    public function unbookmark(Request $request, string $video)
    {
        return $this->handle(function () use ($request, $video) {
            return $this->socialEngagementService->unbookmarkVideo(
                $request->user(),
                Video::query()->findOrFail($video)
            );
        });
    }

    public function follow(Request $request, string $creator)
    {
        return $this->handle(function () use ($request, $creator) {
            return $this->socialEngagementService->followCreator(
                $request->user(),
                User::query()->findOrFail($creator)
            );
        });
    }

    public function unfollow(Request $request, string $creator)
    {
        return $this->handle(function () use ($request, $creator) {
            return $this->socialEngagementService->unfollowCreator(
                $request->user(),
                User::query()->findOrFail($creator)
            );
        });
    }

    public function comment(Request $request, string $video)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        return $this->handle(function () use ($request, $video, $validated) {
            return $this->socialEngagementService->commentOnVideo(
                user: $request->user(),
                video: Video::query()->findOrFail($video),
                body: $validated['body']
            );
        }, 201);
    }

    public function comments(Request $request, string $video)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->handle(function () use ($video, $validated) {
            $comments = $this->socialEngagementService->listVideoComments(
                video: Video::query()->findOrFail($video),
                perPage: (int) ($validated['per_page'] ?? 20),
            );

            return [
                'data' => VideoCommentResource::collection($comments),
            ];
        });
    }

    public function reply(Request $request, string $video, int $comment)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        return $this->handle(function () use ($request, $video, $comment, $validated) {
            return $this->socialEngagementService->commentOnVideo(
                user: $request->user(),
                video: Video::query()->findOrFail($video),
                body: $validated['body'],
                parentId: $comment
            );
        }, 201);
    }

    public function likeComment(Request $request, string $video, int $comment)
    {
        return $this->handle(function () use ($request, $video, $comment) {
            $videoModel = Video::query()->findOrFail($video);
            $commentModel = VideoComment::query()
                ->where('video_id', $videoModel->id)
                ->findOrFail($comment);

            return $this->socialEngagementService->likeComment($request->user(), $commentModel);
        });
    }

    public function unlikeComment(Request $request, string $video, int $comment)
    {
        return $this->handle(function () use ($request, $video, $comment) {
            $videoModel = Video::query()->findOrFail($video);
            $commentModel = VideoComment::query()
                ->where('video_id', $videoModel->id)
                ->findOrFail($comment);

            return $this->socialEngagementService->unlikeComment($request->user(), $commentModel);
        });
    }

    private function handle(callable $callback, int $status = 200)
    {
        try {
            $result = $callback();

            return response()->json($result, $status);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'message' => 'Resource not found.',
            ], 404);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => 'Unable to complete social action.',
                'error' => $throwable->getMessage(),
            ], 500);
        }
    }
}
