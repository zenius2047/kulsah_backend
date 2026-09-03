<?php

namespace Tests\Feature;

use App\Models\Sticker;
use App\Models\StickerPack;
use App\Models\StickerRecent;
use App\Models\User;
use App\Services\StickerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StickerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_use_creates_then_increments_the_recent_sticker_count(): void
    {
        $user = User::factory()->create();
        $pack = StickerPack::query()->create([
            'name' => 'Test Stickers',
            'slug' => 'test-stickers',
        ]);
        $sticker = Sticker::query()->create([
            'sticker_pack_id' => $pack->id,
            'name' => 'Wave',
            'media_url' => 'https://example.com/stickers/wave.png',
        ]);

        $service = app(StickerService::class);
        $service->recordUse($user, $sticker);
        $service->recordUse($user, $sticker);

        $recent = StickerRecent::query()
            ->where('user_id', $user->id)
            ->where('sticker_id', $sticker->id)
            ->sole();

        $this->assertSame(2, (int) $recent->use_count);
        $this->assertSame(2, (int) $sticker->refresh()->usage_count);
        $this->assertNotNull($recent->last_used_at);
    }
}
