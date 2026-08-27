<?php

namespace Database\Seeders;

use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostMedia;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommunityVolumeSeeder extends Seeder
{
    public const TARGET_COUNT = 200;

    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'naledi.fit', 'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $creators = collect([
                $users->get('creator'), $users->get('zuri.moves'), $users->get('tunde.creates'),
                $users->get('naledi.fit'), $users->get('kwame.frames'), $users->get('amina.designs'),
            ]);
            $viewers = collect([$users->get('fan'), $users->get('fans')]);
            $videoAssets = collect(DemoMedia::videos())->values();
            $types = ['text', 'image', 'video', 'poll'];
            $topics = [
                'a rehearsal lesson worth keeping',
                'the detail that made today’s edit work',
                'a small creative win from this week',
                'what the community should build next',
                'a behind-the-scenes decision from the studio',
                'one practical tip for the next creator session',
                'a local story that inspired the next post',
                'the challenge prompt everyone is discussing',
            ];

            for ($number = 1; $number <= self::TARGET_COUNT; $number++) {
                $creator = $creators->get(($number - 1) % $creators->count());
                $viewer = $viewers->get(($number - 1) % $viewers->count());
                $type = $types[($number - 1) % count($types)];
                $ageHours = ($number % 720) + 1;
                $content = sprintf(
                    'Community dispatch %03d: %s. What would you add to the conversation?',
                    $number,
                    $topics[($number - 1) % count($topics)],
                );
                $poll = $type === 'poll' ? [
                    'options' => ['Show the process', 'Share the finished result', 'Host a live session'],
                    'closes_at' => now()->addDays(($number % 14) + 1)->toIso8601String(),
                ] : null;
                $post = CommunityPost::query()->updateOrCreate(
                    ['user_id' => $creator->id, 'content' => $content],
                    [
                        'type' => $type,
                        'audience' => $number % 5 === 0 ? 'subscribers' : 'public',
                        'status' => 'published',
                        'views_count' => 80 + (($number * 97) % 9000),
                        'last_activity_at' => now()->subHours(max(1, $ageHours - 2)),
                        'media_ids' => [],
                        'poll' => $poll,
                    ],
                );
                $createdAt = now()->subHours($ageHours);
                DB::table('community_posts')->where('id', $post->id)->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt->copy()->addHour(),
                ]);

                if (in_array($type, ['image', 'video'], true)) {
                    $asset = $videoAssets->get(($number - 1) % $videoAssets->count());
                    $isVideo = $type === 'video';
                    $extension = $isVideo ? 'mp4' : 'jpg';
                    $sourceKey = sprintf('demo/community/volume-%03d.%s', $number, $extension);
                    $imageUrl = DemoMedia::image('sample', 1080, 1350);
                    $media = CommunityPostMedia::query()->updateOrCreate(
                        ['community_post_id' => $post->id, 'source_key' => $sourceKey],
                        [
                            'media_type' => $type,
                            'disk' => 'remote-demo',
                            'source_url' => $isVideo ? $asset['video_url'] : $imageUrl,
                            'original_name' => basename($sourceKey),
                            'mime_type' => $isVideo ? 'video/mp4' : 'image/jpeg',
                            'sort_order' => 0,
                            'cloudinary_public_id' => $isVideo ? $asset['public_id'] : 'sample',
                            'cloudinary_url' => $isVideo ? $asset['video_url'] : $imageUrl,
                            'cloudinary_stream_url' => $isVideo ? $asset['video_url'] : $imageUrl,
                            'cloudinary_thumbnail_url' => $isVideo ? $asset['poster_url'] : $imageUrl,
                            'metadata' => [
                                'seeded' => true,
                                'seed_group' => 'volume_communities',
                                'provider' => 'Cloudinary public demo cloud',
                            ],
                        ],
                    );
                    $post->update(['media_ids' => [$media->id]]);
                }

                DB::table('community_post_likes')->updateOrInsert(
                    ['community_post_id' => $post->id, 'user_id' => $viewer->id],
                    ['created_at' => $createdAt->copy()->addHour(), 'updated_at' => $createdAt->copy()->addHour()],
                );
                DB::table('community_post_views')->updateOrInsert(
                    ['user_id' => $viewer->id, 'community_post_id' => $post->id],
                    [
                        'first_viewed_at' => $createdAt->copy()->addMinutes(30),
                        'last_viewed_at' => $createdAt->copy()->addHours(2),
                        'view_count' => ($number % 4) + 1,
                        'watch_duration_seconds' => 12 + ($number % 80),
                        'last_watch_duration_seconds' => 8 + ($number % 40),
                        'completion_percentage' => 40 + ($number % 61),
                        'max_completion_percentage' => 40 + ($number % 61),
                        'completed_count' => $number % 3 === 0 ? 1 : 0,
                        'reached_25_percent' => true,
                        'reached_50_percent' => $number % 5 !== 0,
                        'reached_75_percent' => $number % 3 === 0,
                        'reached_90_percent' => $number % 4 === 0,
                        'engaged' => true,
                        'last_engaged_at' => $createdAt->copy()->addHours(2),
                        'last_counted_at' => $createdAt->copy()->addHours(2),
                        'created_at' => $createdAt->copy()->addMinutes(30),
                        'updated_at' => $createdAt->copy()->addHours(2),
                    ],
                );
                DB::table('viewed_contents')->updateOrInsert(
                    ['viewer_id' => $viewer->id, 'viewable_type' => 'community_post', 'viewable_id' => $post->id],
                    [
                        'viewed_at' => $createdAt->copy()->addHours(2),
                        'created_at' => $createdAt->copy()->addMinutes(30),
                        'updated_at' => $createdAt->copy()->addHours(2),
                    ],
                );

                if ($number % 4 === 0) {
                    DB::table('community_post_shares')->updateOrInsert(
                        ['community_post_id' => $post->id, 'user_id' => $viewer->id],
                        ['created_at' => $createdAt->copy()->addHours(3), 'updated_at' => $createdAt->copy()->addHours(3)],
                    );
                }

                if ($number % 3 === 0) {
                    CommunityPostComment::query()->updateOrCreate(
                        [
                            'community_post_id' => $post->id,
                            'user_id' => $viewer->id,
                            'body' => sprintf('This is useful - saving community dispatch %03d.', $number),
                        ],
                        ['parent_id' => null],
                    );
                }

                if ($type === 'poll') {
                    DB::table('community_post_poll_votes')->updateOrInsert(
                        ['community_post_id' => $post->id, 'user_id' => $viewer->id],
                        [
                            'poll_option_index' => $number % 3,
                            'created_at' => $createdAt->copy()->addHours(2),
                            'updated_at' => $createdAt->copy()->addHours(2),
                        ],
                    );
                }
            }
        });
    }
}
