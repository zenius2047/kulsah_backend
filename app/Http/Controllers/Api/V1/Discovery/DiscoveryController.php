<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscoveryCreatorResource;
use App\Http\Resources\DiscoveryEventResource;
use App\Http\Resources\DiscoveryVideoResource;
use App\Services\DiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscoveryController extends Controller
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
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
                'pagination' => [
                    'current_page' => $result['page'],
                    'per_page' => $result['limit'],
                    'has_more' => $result['has_more'],
                ],
            ],
        ]);
    }
}
