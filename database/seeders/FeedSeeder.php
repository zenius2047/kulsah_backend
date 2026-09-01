<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoComment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeedSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $media = DemoMedia::videos();

            $definitions = [
                'coastal-dance' => ['zuri.moves', 'Coastal footwork challenge', 'Sun out, sound up. Show me your cleanest eight-count. #CoastalMoves #DanceAfrica', true, 'post_video', 18420, 2],
                'coastal-style' => ['creator', 'Golden-hour style check', 'One wrap, three looks, and the Accra sunset doing the rest. #AfricanFashion #Style', true, 'post_video', 12750, 5],
                'dog-reaction' => ['tunde.creates', 'When the beat finally drops', 'POV: the DJ remembers the song you requested two hours ago. #Comedy #Afrobeats', true, 'post_video', 32690, 8],
                'dog-remix' => ['tunde.creates', 'The wholesome remix', 'Had to answer my own skit with the ending everyone asked for. #Duet #Comedy', false, 'post_video', 9780, 12],
                'ski-freestyle' => ['naledi.fit', 'Snow-day freestyle', 'Balance, breath, and a little courage. Save this for your next adventure. #Fitness #Adventure', true, 'post_video', 22110, 18],
                'ski-battle' => ['naledi.fit', 'Freestyle battle entry', 'Bringing mountain energy to this creator battle. Vote if this landing made you smile. #CreatorBattle', false, 'challenge_entry', 15880, 22],
                'ship-travel' => ['kwame.frames', 'Atlantic morning', 'No rush—just the horizon and the sound of water. #TravelAfrica #SlowLiving', true, 'post_video', 14520, 28],
                'ship-story' => ['kwame.frames', 'A story from the water', 'The short version of a journey that changed how I frame home. #Storytelling #Travel', false, 'challenge_entry', 11240, 32],
                'bathroom-design' => ['amina.designs', 'A calmer small space', 'Warm light, useful storage, and room to breathe. #InteriorDesign #HomeIdeas', true, 'post_video', 8930, 38],
                'bathroom-tips' => ['amina.designs', 'Three details that change a room', 'Texture, lighting, and one honest focal point. Which would you try first? #DesignTips', false, 'challenge_entry', 7640, 44],
                'challenge-instructions' => ['creator', 'Coastal Moves official instructions', 'Use the official beat, keep it under 30 seconds, and tag #CoastalMoves. Make it yours.', true, 'challenge_instruction_video', 5260, 50],
                'battle-instructions' => ['naledi.fit', 'Creator Battle: Freestyle Face-Off', 'Two creators enter, the community decides. Watch both entries before voting.', true, 'challenge_instruction_video', 6110, 54],
                'ama-dance-tutorial' => ['creator', 'Three steps to cleaner transitions', 'Slow it down, find the balance point, then add your own texture. #DanceTutorial #LearnOnKulsah', true, 'post_video', 8390, 58],
                'zuri-warmup' => ['zuri.moves', 'Five-minute movement warm-up', 'Save this before your next rehearsal. Your ankles and hips will thank you. #WarmUp #DanceFitness', true, 'post_video', 10940, 62],
                'tunde-behind-scenes' => ['tunde.creates', 'How the joke changed on set', 'The first ending did not work, so we tried the quiet version. #BehindTheScenes #Comedy', true, 'post_video', 12880, 66],
                'naledi-mobility' => ['naledi.fit', 'Mobility before adventure', 'Three controlled movements before a long active day. #Mobility #FitnessTips', true, 'post_video', 9160, 70],
                'kwame-color-grade' => ['kwame.frames', 'From flat footage to ocean blues', 'A quick look at the colour choices behind the final travel story. #ColorGrade #Filmmaking', true, 'post_video', 7340, 74],
                'amina-moodboard' => ['amina.designs', 'Building a warm room mood board', 'Start with feeling, then choose material, colour, and light. #MoodBoard #InteriorDesign', true, 'post_video', 6850, 78],
            ];

            $videos = collect();

            foreach ($definitions as $key => [$username, $title, $caption, $allowDuet, $purpose, $views, $ageHours]) {
                $asset = $media[$key];
                $video = Video::query()->updateOrCreate(
                    ['source_key' => "demo/{$key}.mp4"],
                    [
                        'user_id' => $users->get($username)->id,
                        'media_type' => 'video',
                        'purpose' => $purpose,
                        'title' => $title,
                        'caption' => $caption,
                        'visibility' => $key === 'bathroom-tips' ? 'premium' : 'public',
                        'allow_duet' => $allowDuet,
                        'content_type' => $asset['content_types'][0],
                        'content_types' => $asset['content_types'],
                        'original_filename' => str_replace('/', '-', $asset['public_id']).'.mp4',
                        'mime_type' => 'video/mp4',
                        'source_disk' => 'remote-demo',
                        'source_bucket' => 'cloudinary-demo',
                        'source_url' => $asset['video_url'],
                        'upload_status' => 'uploaded',
                        'processing_status' => 'ready',
                        'cdn_url' => $asset['video_url'],
                        'rendered_url' => $asset['video_url'],
                        'streaming_url' => $asset['video_url'],
                        'playback_type' => 'progressive',
                        'fallback_mp4_url' => $asset['video_url'],
                        'poster_url' => $asset['poster_url'],
                        'thumbnail_url' => $asset['poster_url'],
                        'cloudinary_public_id' => $asset['public_id'],
                        'duration' => $asset['duration'],
                        'duration_ms' => $asset['duration'] * 1000,
                        'width' => 720,
                        'height' => 1280,
                        'aspect_ratio' => '9:16',
                        'fps' => 30,
                        'status' => 'ready',
                        'render_status' => 'completed',
                        'uploaded_at' => now()->subHours($ageHours)->subMinutes(5),
                        'processed_at' => now()->subHours($ageHours),
                        'progress_percentage' => 100,
                        'render_completed_at' => now()->subHours($ageHours),
                        'views_count' => $views,
                        'metadata' => [
                            'seeded' => true,
                            'topic' => $asset['content_types'][0],
                            'category' => $asset['content_types'][0],
                            'caption_hashtags' => $this->hashtags($caption),
                            'caption_mentions' => [],
                            'shares_count' => max(12, intdiv($views, 55)),
                            'audio_title' => 'Kulsah Demo Sound',
                            'is_original' => true,
                            'allow_remix' => $allowDuet,
                            'allow_download' => true,
                            'media_provider' => $asset['provider'],
                            'media_source_page' => $asset['source_page'],
                            'demo_asset_public_id' => $asset['public_id'],
                        ],
                    ],
                );

                DB::table('videos')->where('id', $video->id)->update([
                    'created_at' => now()->subHours($ageHours),
                    'updated_at' => now()->subHours($ageHours),
                ]);
                $videos->put($key, $video);
            }

            $duetSource = $videos->get('dog-reaction');
            $duet = $videos->get('dog-remix');
            $duet->update([
                'duet_source_video_id' => $duetSource->id,
                'metadata' => array_merge($duet->metadata, [
                    'duet_composition_id' => 'demo-dog-remix',
                    'duet_layout' => 'side_by_side',
                    'duet_source_position' => 'left',
                    'duet_response_position' => 'right',
                    'duet_render_status' => 'completed',
                ]),
            ]);

            $this->seedFollows($users);
            $this->seedEngagement($users, $videos);
        });
    }

    private function seedFollows($users): void
    {
        $relationships = [
            ['fan', 'creator'], ['fan', 'zuri.moves'], ['fan', 'tunde.creates'], ['fan', 'naledi.fit'],
            ['fans', 'creator'], ['fans', 'kwame.frames'], ['fans', 'amina.designs'],
            ['creator', 'zuri.moves'], ['zuri.moves', 'creator'], ['tunde.creates', 'naledi.fit'],
            ['naledi.fit', 'tunde.creates'], ['kwame.frames', 'amina.designs'], ['amina.designs', 'kwame.frames'],
        ];

        foreach ($relationships as [$follower, $followed]) {
            DB::table('user_follows')->updateOrInsert(
                ['follower_id' => $users->get($follower)->id, 'followed_id' => $users->get($followed)->id],
                ['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)],
            );
        }
    }

    private function seedEngagement($users, $videos): void
    {
        $likes = [
            ['fan', 'coastal-dance'], ['fan', 'coastal-style'], ['fan', 'dog-reaction'], ['fan', 'ski-freestyle'],
            ['fans', 'coastal-dance'], ['fans', 'ship-travel'], ['fans', 'bathroom-design'],
            ['creator', 'coastal-dance'], ['zuri.moves', 'coastal-style'], ['tunde.creates', 'ski-freestyle'],
            ['naledi.fit', 'dog-reaction'], ['kwame.frames', 'bathroom-design'], ['amina.designs', 'ship-travel'],
        ];
        foreach ($likes as [$username, $videoKey]) {
            DB::table('video_likes')->updateOrInsert(
                ['video_id' => $videos->get($videoKey)->id, 'user_id' => $users->get($username)->id],
                ['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)],
            );
        }

        foreach ([['fan', 'ship-travel'], ['fan', 'bathroom-design'], ['fans', 'coastal-style'], ['fans', 'ski-freestyle']] as [$username, $videoKey]) {
            DB::table('video_bookmarks')->updateOrInsert(
                ['video_id' => $videos->get($videoKey)->id, 'user_id' => $users->get($username)->id],
                ['created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            );
        }

        foreach ([
            ['fan', 'coastal-dance'], ['fan', 'dog-reaction'], ['fan', 'ship-travel'],
            ['fans', 'coastal-style'], ['fans', 'ski-freestyle'], ['fans', 'bathroom-design'],
        ] as [$username, $videoKey]) {
            $viewerId = $users->get($username)->id;
            $videoId = $videos->get($videoKey)->id;
            DB::table('video_views')->updateOrInsert(
                ['video_id' => $videoId, 'user_id' => $viewerId],
                ['viewed_at' => now()->subMinutes(45), 'created_at' => now()->subMinutes(45), 'updated_at' => now()->subMinutes(45)],
            );
            DB::table('viewed_contents')->updateOrInsert(
                ['viewer_id' => $viewerId, 'viewable_type' => 'discovery_video', 'viewable_id' => $videoId],
                ['viewed_at' => now()->subMinutes(45), 'created_at' => now()->subMinutes(45), 'updated_at' => now()->subMinutes(45)],
            );
        }

        $comment = VideoComment::query()->updateOrCreate(
            ['video_id' => $videos->get('coastal-dance')->id, 'user_id' => $users->get('fan')->id, 'body' => 'That last transition is so clean!'],
            ['parent_id' => null],
        );
        $reply = VideoComment::query()->updateOrCreate(
            ['video_id' => $videos->get('coastal-dance')->id, 'user_id' => $users->get('zuri.moves')->id, 'body' => 'Thank you! Full breakdown is coming this week.'],
            ['parent_id' => $comment->id],
        );
        VideoComment::query()->updateOrCreate(
            ['video_id' => $videos->get('dog-reaction')->id, 'user_id' => $users->get('fans')->id, 'body' => 'The timing got me 😂'],
            ['parent_id' => null],
        );
        DB::table('video_comment_likes')->updateOrInsert(
            ['video_comment_id' => $reply->id, 'user_id' => $users->get('fan')->id],
            ['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        );
    }

    /**
     * @return array<int, string>
     */
    private function hashtags(string $caption): array
    {
        preg_match_all('/#[\pL\pN_]+/u', $caption, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
