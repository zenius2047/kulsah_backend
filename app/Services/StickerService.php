<?php

namespace App\Services;

use App\Models\Sticker;
use App\Models\StickerFavorite;
use App\Models\StickerRecent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StickerService
{
    public function visibleTo(?User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Sticker::query()->where('is_active', true)->where(function ($query) use ($user): void {
            $query->where(function ($public): void {
                $public->whereIn('visibility', ['public', 'official'])->where('moderation_status', 'approved');
            });
            if ($user) $query->orWhere(fn ($private) => $private->where('owner_id', $user->id)->where('visibility', 'private'));
        });
    }

    public function findUsable(User $user, int $id): Sticker
    {
        $sticker = $this->visibleTo($user)->with('pack')->find($id);
        if (! $sticker) throw ValidationException::withMessages(['sticker_id' => 'Sticker is unavailable.']);
        return $sticker;
    }

    public function recordUse(User $user, Sticker $sticker): void
    {
        DB::transaction(function () use ($user, $sticker): void {
            $sticker->increment('usage_count');
            StickerRecent::query()->updateOrCreate(
                ['user_id' => $user->id, 'sticker_id' => $sticker->id],
                ['last_used_at' => now(), 'use_count' => DB::raw('use_count + 1')]
            );
            StickerRecent::query()->where('user_id', $user->id)->orderByDesc('last_used_at')->skip(50)->take(PHP_INT_MAX)->delete();
        });
    }

    public function toggleFavorite(User $user, Sticker $sticker): bool
    {
        $favorite = StickerFavorite::query()->where(['user_id' => $user->id, 'sticker_id' => $sticker->id])->first();
        if ($favorite) { $favorite->delete(); $sticker->decrement('favorite_count'); return false; }
        StickerFavorite::query()->create(['user_id' => $user->id, 'sticker_id' => $sticker->id]);
        $sticker->increment('favorite_count');
        return true;
    }

    public function search(?User $user, string $term, int $perPage = 30): LengthAwarePaginator
    {
        return $this->visibleTo($user)->with('pack')->where(function ($query) use ($term): void {
            $like = '%'.addcslashes($term, '%_').'%';
            $query->where('name', 'like', $like)->orWhereJsonContains('tags', strtolower($term))->orWhereHas('pack', fn ($pack) => $pack->where('name', 'like', $like));
        })->latest()->paginate($perPage);
    }
}

