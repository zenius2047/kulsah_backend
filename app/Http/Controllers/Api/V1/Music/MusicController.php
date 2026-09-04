<?php

namespace App\Http\Controllers\Api\V1\Music;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Music\MusicQueryRequest;
use App\Http\Resources\MusicResource;
use App\Services\MusicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class MusicController extends Controller
{
    public function __construct(
        private readonly MusicService $musicService,
    ) {
    }

    public function index(MusicQueryRequest $request): JsonResponse
    {
        $payload = $this->musicService->browse($request->validated(), $request->user());

        return response()->json([
            'data' => MusicResource::collection(collect($payload['items'] ?? []))->resolve($request),
            'meta' => $payload['meta'] ?? [],
        ]);
    }

    public function show(Request $request, string $musicTrack): JsonResponse
    {
        $payload = $this->musicService->show($musicTrack, $request->user());

        return response()->json([
            'data' => new MusicResource($payload['data'] ?? []),
            'meta' => $payload['meta'] ?? [],
        ]);
    }

    public function stream(Request $request, string $musicTrack): SymfonyResponse
    {
        try {
            return $this->musicService->stream($musicTrack, $request->user());
        } catch (Throwable $throwable) {
            Log::warning('Music stream resolution failed.', [
                'music_track' => $musicTrack,
                'message' => $throwable->getMessage(),
                'exception' => $throwable::class,
            ]);

            throw $throwable;
        }
    }
}
