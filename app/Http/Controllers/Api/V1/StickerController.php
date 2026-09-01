<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StickerResource;
use App\Models\Sticker;
use App\Models\StickerFavorite;
use App\Models\StickerPack;
use App\Models\StickerRecent;
use App\Services\StickerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StickerController extends Controller
{
    public function __construct(private readonly StickerService $stickers) {}

    public function index(Request $request)
    {
        $packs = StickerPack::query()->where('is_active', true)->where(function ($query) use ($request): void {
            $query->where(fn ($public) => $public->where('is_public', true)->where('is_official', true))
                ->orWhere('owner_id', $request->user()->id);
        })->withCount('stickers')->orderByDesc('is_featured')->orderBy('sort_order')->paginate($request->integer('per_page', 30));
        return response()->json(['data' => $packs]);
    }

    public function pack(Request $request, StickerPack $stickerPack)
    {
        abort_unless($stickerPack->is_active && ($stickerPack->is_public || (int) $stickerPack->owner_id === (int) $request->user()->id), 404);
        return response()->json(['data' => StickerResource::collection($stickerPack->stickers()->whereIn('id', $this->stickers->visibleTo($request->user())->select('stickers.id'))->paginate($request->integer('per_page', 50))) ]);
    }

    public function search(Request $request)
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return response()->json(['data' => StickerResource::collection($this->stickers->search($request->user(), $validated['q'], (int) ($validated['per_page'] ?? 30)))]);
    }

    public function recent(Request $request)
    {
        $items = StickerRecent::query()->where('user_id', $request->user()->id)->with('sticker.pack')->latest('last_used_at')->paginate($request->integer('per_page', 50));
        return response()->json(['data' => StickerResource::collection($items->through(fn (StickerRecent $recent) => $recent->sticker))]);
    }

    public function favorites(Request $request)
    {
        $items = StickerFavorite::query()->where('user_id', $request->user()->id)->with('sticker.pack')->latest()->paginate($request->integer('per_page', 50));
        return response()->json(['data' => StickerResource::collection($items->through(fn (StickerFavorite $favorite) => $favorite->sticker))]);
    }

    public function favorite(Request $request, Sticker $sticker)
    {
        $sticker = $this->stickers->findUsable($request->user(), $sticker->id);
        return response()->json(['favorited' => $this->stickers->toggleFavorite($request->user(), $sticker)]);
    }

    public function use(Request $request, Sticker $sticker)
    {
        $sticker = $this->stickers->findUsable($request->user(), $sticker->id);
        $this->stickers->recordUse($request->user(), $sticker);
        return response()->json(['data' => new StickerResource($sticker)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'media_url' => ['required', 'url', 'max:2048'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'], 'type' => ['required', Rule::in(['static', 'animated_webp', 'gif'])],
            'visibility' => ['required', Rule::in(['private', 'public'])], 'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:50'],
            'width' => ['nullable', 'integer', 'min:1', 'max:4096'], 'height' => ['nullable', 'integer', 'min:1', 'max:4096'],
        ]);
        $public = $validated['visibility'] === 'public';
        $pack = StickerPack::query()->create(['owner_id' => $request->user()->id, 'owner_type' => 'user', 'name' => $request->user()->name.' stickers', 'slug' => 'user-'.$request->user()->id.'-stickers', 'is_public' => $public, 'is_official' => false]);
        $sticker = $pack->stickers()->create(array_merge($validated, ['owner_id' => $request->user()->id, 'moderation_status' => $public ? 'pending' : 'approved', 'visibility' => $validated['visibility'], 'is_animated' => $validated['type'] !== 'static']));
        return response()->json(['data' => new StickerResource($sticker->load('pack'))], 201);
    }

    public function destroy(Request $request, Sticker $sticker)
    {
        abort_unless((int) $sticker->owner_id === (int) $request->user()->id && $sticker->visibility !== 'official', 403);
        $sticker->delete();
        StickerFavorite::query()->where('sticker_id', $sticker->id)->delete();
        StickerRecent::query()->where('sticker_id', $sticker->id)->delete();
        return response()->noContent();
    }
}

