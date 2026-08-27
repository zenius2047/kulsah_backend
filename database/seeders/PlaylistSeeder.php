<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoPlaylist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlaylistSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'creator', 'zuri.moves', 'tunde.creates', 'naledi.fit',
                'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $videos = Video::query()
                ->where('source_key', 'like', 'demo/%')
                ->get()
                ->keyBy(fn (Video $video): string => pathinfo((string) $video->source_key, PATHINFO_FILENAME));

            $playlists = [
                ['creator', 'Dance Tutorials', ['ama-dance-tutorial', 'coastal-style']],
                ['creator', 'Challenge Guides', ['challenge-instructions']],
                ['creator', 'Style & Movement', ['coastal-style', 'ama-dance-tutorial', 'challenge-instructions']],
                ['zuri.moves', 'Choreography Lab', ['zuri-warmup', 'coastal-dance']],
                ['zuri.moves', 'Coastal Moves', ['coastal-dance']],
                ['zuri.moves', 'Draft Ideas', []],
                ['tunde.creates', 'Comedy Cuts', ['dog-reaction', 'dog-remix', 'tunde-behind-scenes']],
                ['tunde.creates', 'Duets & Reactions', ['dog-remix', 'dog-reaction']],
                ['tunde.creates', 'Behind the Scenes', ['tunde-behind-scenes']],
                ['naledi.fit', 'Training Sessions', ['naledi-mobility', 'ski-freestyle']],
                ['naledi.fit', 'Adventure', ['ski-freestyle', 'ski-battle']],
                ['naledi.fit', 'Creator Battles', ['battle-instructions', 'ski-battle']],
                ['kwame.frames', 'Travel Stories', ['ship-travel', 'ship-story']],
                ['kwame.frames', 'Filmmaking Field Notes', ['kwame-color-grade', 'ship-story', 'ship-travel']],
                ['amina.designs', 'Design Lessons', ['amina-moodboard', 'bathroom-design', 'bathroom-tips']],
                ['amina.designs', 'Room Refresh', ['bathroom-design', 'amina-moodboard']],
                ['amina.designs', 'Subscriber Exclusives', ['bathroom-tips']],
            ];

            foreach ($playlists as [$username, $name, $videoKeys]) {
                $user = $users->get($username);
                $playlist = VideoPlaylist::query()->updateOrCreate(
                    ['user_id' => $user->id, 'name' => $name],
                );

                foreach ($videoKeys as $position => $videoKey) {
                    $video = $videos->get($videoKey);

                    if (! $video || (int) $video->user_id !== (int) $user->id) {
                        throw new \RuntimeException("Playlist video {$videoKey} is missing or is not owned by {$username}.");
                    }

                    DB::table('video_playlist_video')->updateOrInsert(
                        ['video_playlist_id' => $playlist->id, 'video_id' => $video->id],
                        [
                            'created_at' => now()->subDays(5)->addMinutes($position),
                            'updated_at' => now()->subDays(5)->addMinutes($position),
                        ],
                    );
                }
            }
        });
    }
}
