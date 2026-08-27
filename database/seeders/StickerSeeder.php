<?php

namespace Database\Seeders;

use App\Models\StickerPack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StickerSeeder extends Seeder
{
    public function run(): void
    {
        $packs = [
            ['Kulsah Reactions', 'reactions', 'Universal reactions', null],
            ['Ghana Vibes', 'ghana-vibes', 'Everyday Ghanaian reactions', 'en'],
            ['Akan / Twi', 'akan-twi', 'Akan and Twi phrases', 'tw'],
            ['Ewe', 'ewe', 'Ewe conversational phrases', 'ee'],
            ['Ga', 'ga', 'Ga conversational phrases', 'gaa'],
            ['Hausa', 'hausa', 'Hausa conversational phrases', 'ha'],
        ];
        $phrases = [
            'reactions' => ['Laugh', 'Love', 'Fire', 'Clap', 'Wow', 'Thinking', 'Party', 'Sleepy'],
            'ghana-vibes' => ['Chale!', 'Ei!', 'Herh!', 'Masa!', 'Vim!', 'Vim Dey!', 'Aswear!', 'Yawa!', 'Bossu!', 'We Move!', 'No Shaking', 'I Dey!'],
            'akan-twi' => ['Medaase', 'Aane', 'Daabi', 'Ete sen?', 'Adɛn?', 'Yoo', 'Wo yɛ guy', 'Massa', 'Nyame Adom'],
            'ewe' => ['Akpe', 'Ao', 'Miawoezo', 'Vim', 'Yoo'],
            'ga' => ['Oyiwala don', 'Mii shɔ', 'Agoo', 'Yoo', 'Chale'],
            'hausa' => ['Na gode', 'Sannu', 'Madalla', 'Haka ne', 'In sha Allah'],
        ];
        foreach ($packs as [$name, $slug, $description, $language]) {
            $pack = StickerPack::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'description' => $description, 'owner_type' => 'kulsah',
                'is_official' => true, 'is_public' => true, 'is_active' => true, 'language' => $language,
            ]);
            foreach ($phrases[$slug] as $position => $phrase) {
                $pack->stickers()->updateOrCreate(['name' => $phrase], [
                    'owner_id' => null, 'type' => 'static', 'media_url' => 'https://placehold.co/512x512/png?text='.urlencode($phrase),
                    'thumbnail_url' => 'https://placehold.co/128x128/png?text='.urlencode($phrase), 'visibility' => 'official',
                    'moderation_status' => 'approved', 'tags' => array_values(array_filter([Str::lower($phrase), $slug])), 'is_active' => true,
                ]);
            }
        }
    }
}




