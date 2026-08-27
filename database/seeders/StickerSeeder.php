<?php

namespace Database\Seeders;

use App\Models\StickerPack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StickerSeeder extends Seeder
{
    public function run(): void
    {
        $stickers = [
            [1, "Let's Vibe", ['vibe', 'fire', 'ghana']],
            [2, 'Kulsah', ['kulsah', 'brand']],
            [3, 'Wassup on Kulsah', ['wassup', 'greeting', 'kulsah']],
            [4, 'Heyy', ['hello', 'hey', 'greeting']],
            [5, 'Good Vibes', ['good vibes', 'music', 'happy']],
            [6, 'Ghana Vibes', ['ghana', 'flag', 'vibes']],
            [7, 'Thumbs Up', ['approve', 'yes', 'like']],
            [8, 'Heart Hands', ['love', 'heart', 'care']],
            [9, 'Clap', ['clap', 'applause', 'well done']],
            [10, '100', ['100', 'perfect', 'respect']],
            [11, 'Crying', ['cry', 'sad', 'tears']],
            [12, 'Heart Eyes', ['love', 'happy', 'crush']],
            [13, 'Fire', ['fire', 'hot', 'trending']],
            [14, 'Red Heart', ['love', 'heart']],
            [15, 'Laughing', ['laugh', 'funny', 'happy']],
            [16, 'Chale', ['chale', 'tired', 'ghana']],
            [17, 'Ei', ['ei', 'surprise', 'ghana']],
            [18, 'No Wahala', ['no wahala', 'okay', 'chill']],
            [19, "I'm Tired Boss", ['tired', 'sleepy', 'boss']],
            [20, 'Sharp', ['sharp', 'smart', 'win']],
            [21, 'Deal', ['deal', 'agreement', 'handshake']],
            [22, 'Next Time', ['next time', 'later', 'maybe']],
            [23, 'Thank You', ['thank you', 'thanks', 'gratitude']],
            [24, 'Respect', ['respect', 'honor']],
            [25, 'Stay Hydrated', ['water', 'health', 'hydrated']],
            [26, 'Mood', ['mood', 'music', 'relaxed']],
            [27, 'Cuteee', ['cute', 'love', 'adorable']],
            [28, 'You Dey Mad', ['mad', 'surprise', 'funny']],
            [29, 'See You on Kulsah', ['goodbye', 'see you', 'kulsah']],
            [30, 'Kulsah Heart', ['kulsah', 'love', 'brand']],
            [31, 'Dream Big', ['dream', 'goals', 'inspiration']],
            [32, 'Capture the Moment', ['camera', 'memory', 'moment']],
            [33, 'Go Create', ['create', 'inspiration', 'action']],
            [34, 'Kulsah App', ['kulsah', 'app', 'brand']],
        ];

        $disk = Storage::disk('s3');
        $pack = StickerPack::query()->updateOrCreate(['slug' => 'kulsah-stickers'], [
            'name' => 'Kulsah Stickers',
            'description' => 'Official Kulsah stickers for chats, comments, and posts.',
            'cover_url' => $disk->url('stickers/kulsah/S1.png'),
            'category' => 'kulsah',
            'language' => 'en',
            'country_code' => 'GH',
            'owner_type' => 'kulsah',
            'is_official' => true,
            'is_public' => true,
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 0,
        ]);

        foreach ($stickers as [$number, $name, $tags]) {
            $url = $disk->url("stickers/kulsah/S{$number}.png");

            $pack->stickers()->updateOrCreate(['name' => $name], [
                'owner_id' => null,
                'type' => 'static',
                'media_url' => $url,
                'thumbnail_url' => $url,
                'visibility' => 'official',
                'moderation_status' => 'approved',
                'is_active' => true,
                'tags' => array_values(array_unique(array_merge($tags, [Str::lower($name), 'kulsah']))),
                'language' => 'en',
            ]);
        }
    }
}
