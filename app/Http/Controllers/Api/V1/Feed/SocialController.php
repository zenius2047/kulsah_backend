<?php

namespace App\Http\Controllers\Api\V1\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoCommentResource;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoComment;
use App\Services\SocialEngagementService;
use App\Services\VideoCacheService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialController extends Controller
{
    public function __construct(
        private readonly SocialEngagementService $socialEngagementService,
        private readonly VideoCacheService $videoCacheService,
    ) {
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
            'body' => ['nullable', 'string', 'required_without:sticker_id', 'min:1', 'max:5000'],
            'sticker_id' => ['nullable', 'integer'],
        ]);

        return $this->handle(function () use ($request, $video, $validated) {
            return $this->socialEngagementService->commentOnVideo(
                user: $request->user(),
                video: Video::query()->findOrFail($video),
                body: $validated['body'] ?? '',
                stickerId: $validated['sticker_id'] ?? null
            );
        }, 201);
    }

    public function comments(Request $request, string $video)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);
        $videoModel = Video::query()->findOrFail($video);

        $payload = $this->videoCacheService->rememberVideo(
            videoId: (int) $videoModel->id,
            scope: 'comments:index',
            context: [
                'page' => $page,
                'per_page' => $perPage,
            ],
            resolver: function () use ($videoModel, $perPage, $page, $request): array {
                $comments = $this->socialEngagementService->listVideoComments(
                    video: $videoModel,
                    perPage: $perPage,
                );

                $comments->setCollection($comments->getCollection()->values());

                return [
                    'data' => VideoCommentResource::collection($comments->getCollection())->resolve($request),
                    'meta' => [
                        'current_page' => $comments->currentPage(),
                        'last_page' => $comments->lastPage(),
                        'per_page' => $comments->perPage(),
                        'total' => $comments->total(),
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function reply(Request $request, string $video, int $comment)
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'required_without:sticker_id', 'min:1', 'max:5000'],
            'sticker_id' => ['nullable', 'integer'],
        ]);

        return $this->handle(function () use ($request, $video, $comment, $validated) {
            return $this->socialEngagementService->commentOnVideo(
                user: $request->user(),
                video: Video::query()->findOrFail($video),
                body: $validated['body'] ?? '',
                stickerId: $validated['sticker_id'] ?? null,
                parentId: $comment,
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



