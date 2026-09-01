<?php

namespace Database\Seeders;

use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostGift;
use App\Models\CommunityPostMedia;
use App\Models\KulCoinGift;
use App\Models\KulCoinTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommunitySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
                'kwame.frames', 'amina.designs',
            ])->get()->keyBy('username');
            $videoAsset = DemoMedia::videos()['ship-travel'];

            $definitions = [
                'welcome' => ['creator', 'text', 'The studio is open ✨ What would you like to see in the next behind-the-scenes post?', 'public', null, 680, 3],
                'dance-board' => ['zuri.moves', 'image', 'This week’s movement board: ocean air, bold colour, and footwork that feels light.', 'public', null, 1240, 7],
                'travel-diary' => ['tunde.creates', 'video', 'A quiet travel minute before the next comedy drop. Sound on for the water.', 'public', null, 2160, 13],
                'design-poll' => ['amina.designs', 'poll', 'Which room should I redesign with you next?', 'subscribers', [
                    'options' => ['A compact bedroom', 'A creator studio', 'A warm kitchen'],
                    'closes_at' => now()->addDays(5)->toIso8601String(),
                ], 940, 19],
                'field-notes' => ['kwame.frames', 'text', 'Subscriber field notes are up: the shot list, weather plan, and what I would change next time.', 'subscribers', null, 510, 27],
            ];

            $posts = collect();
            foreach ($definitions as $key => [$username, $type, $content, $audience, $poll, $views, $ageHours]) {
                $post = CommunityPost::query()->updateOrCreate(
                    ['user_id' => $users->get($username)->id, 'content' => $content],
                    [
                        'type' => $type,
                        'audience' => $audience,
                        'status' => 'published',
                        'views_count' => $views,
                        'last_activity_at' => now()->subHours(max(1, $ageHours - 1)),
                        'media_ids' => [],
                        'poll' => $poll,
                    ],
                );
                DB::table('community_posts')->where('id', $post->id)->update([
                    'created_at' => now()->subHours($ageHours),
                    'updated_at' => now()->subHours(max(1, $ageHours - 1)),
                ]);
                $posts->put($key, $post);
            }

            $danceImage = DemoMedia::image('sample', 1080, 1350);
            $image = CommunityPostMedia::query()->updateOrCreate(
                ['community_post_id' => $posts->get('dance-board')->id, 'source_key' => 'demo/community/dance-board.jpg'],
                [
                    'media_type' => 'image',
                    'disk' => 'remote-demo',
                    'source_url' => $danceImage,
                    'original_name' => 'dance-board.jpg',
                    'mime_type' => 'image/jpeg',
                    'sort_order' => 0,
                    'cloudinary_public_id' => 'sample',
                    'cloudinary_url' => $danceImage,
                    'cloudinary_stream_url' => $danceImage,
                    'cloudinary_thumbnail_url' => $danceImage,
                    'metadata' => ['seeded' => true, 'provider' => 'Cloudinary public demo cloud'],
                ],
            );
            $posts->get('dance-board')->update(['media_ids' => [$image->id]]);

            $video = CommunityPostMedia::query()->updateOrCreate(
                ['community_post_id' => $posts->get('travel-diary')->id, 'source_key' => 'demo/community/travel-diary.mp4'],
                [
                    'media_type' => 'video',
                    'disk' => 'remote-demo',
                    'source_url' => $videoAsset['video_url'],
                    'original_name' => 'travel-diary.mp4',
                    'mime_type' => 'video/mp4',
                    'sort_order' => 0,
                    'cloudinary_public_id' => $videoAsset['public_id'],
                    'cloudinary_url' => $videoAsset['video_url'],
                    'cloudinary_stream_url' => $videoAsset['video_url'],
                    'cloudinary_thumbnail_url' => $videoAsset['poster_url'],
                    'metadata' => [
                        'seeded' => true,
                        'duration_seconds' => $videoAsset['duration'],
                        'provider' => $videoAsset['provider'],
                        'source_page' => $videoAsset['source_page'],
                    ],
                ],
            );
            $posts->get('travel-diary')->update(['media_ids' => [$video->id]]);

            $this->seedEngagement($users, $posts);
        });
    }

    private function seedEngagement($users, $posts): void
    {
        foreach ([
            ['fan', 'welcome'], ['fans', 'welcome'], ['fan', 'dance-board'], ['fans', 'dance-board'],
            ['creator', 'dance-board'], ['fan', 'travel-diary'], ['fans', 'travel-diary'],
            ['fan', 'design-poll'], ['fans', 'field-notes'],
        ] as [$username, $postKey]) {
            DB::table('community_post_likes')->updateOrInsert(
                ['community_post_id' => $posts->get($postKey)->id, 'user_id' => $users->get($username)->id],
                ['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)],
            );
        }

        foreach ([['fan', 'dance-board'], ['fans', 'travel-diary'], ['creator', 'travel-diary']] as [$username, $postKey]) {
            DB::table('community_post_shares')->updateOrInsert(
                ['community_post_id' => $posts->get($postKey)->id, 'user_id' => $users->get($username)->id],
                ['created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            );
        }

        $comment = CommunityPostComment::query()->updateOrCreate(
            [
                'community_post_id' => $posts->get('welcome')->id,
                'user_id' => $users->get('fan')->id,
                'body' => 'Please show us how you plan the transitions!',
            ],
            ['parent_id' => null],
        );
        CommunityPostComment::query()->updateOrCreate(
            [
                'community_post_id' => $posts->get('welcome')->id,
                'user_id' => $users->get('creator')->id,
                'body' => 'Deal — I’ll share the storyboard and the final cut.',
            ],
            ['parent_id' => $comment->id],
        );
        CommunityPostComment::query()->updateOrCreate(
            [
                'community_post_id' => $posts->get('travel-diary')->id,
                'user_id' => $users->get('fans')->id,
                'body' => 'This is unexpectedly peaceful. Saving it for later.',
            ],
            ['parent_id' => null],
        );

        foreach ([['fan', 1], ['fans', 2], ['creator', 1]] as [$username, $optionId]) {
            DB::table('community_post_poll_votes')->updateOrInsert(
                ['community_post_id' => $posts->get('design-poll')->id, 'user_id' => $users->get($username)->id],
                ['poll_option_index' => $optionId - 1, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            );
        }

        foreach ([
            ['fan', 'welcome', 1, 0, 100], ['fan', 'travel-diary', 2, 25, 92],
            ['fans', 'dance-board', 1, 0, 100], ['fans', 'design-poll', 1, 0, 100],
        ] as [$username, $postKey, $viewCount, $watchSeconds, $completion]) {
            $userId = $users->get($username)->id;
            $postId = $posts->get($postKey)->id;
            DB::table('community_post_views')->updateOrInsert(
                ['user_id' => $userId, 'community_post_id' => $postId],
                [
                    'first_viewed_at' => now()->subDays(2),
                    'last_viewed_at' => now()->subHours(8),
                    'view_count' => $viewCount,
                    'watch_duration_seconds' => $watchSeconds,
                    'last_watch_duration_seconds' => $watchSeconds,
                    'completion_percentage' => $completion,
                    'max_completion_percentage' => $completion,
                    'completed_count' => $completion >= 99 ? 1 : 0,
                    'reached_25_percent' => $completion >= 25,
                    'reached_50_percent' => $completion >= 50,
                    'reached_75_percent' => $completion >= 75,
                    'reached_90_percent' => $completion >= 90,
                    'engaged' => true,
                    'last_engaged_at' => now()->subHours(7),
                    'last_counted_at' => now()->subHours(8),
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subHours(7),
                ],
            );
            DB::table('viewed_contents')->updateOrInsert(
                ['viewer_id' => $userId, 'viewable_type' => 'community_post', 'viewable_id' => $postId],
                ['viewed_at' => now()->subHours(8), 'created_at' => now()->subDays(2), 'updated_at' => now()->subHours(8)],
            );
        }

        $gift = KulCoinGift::query()->where('code', 'rose')->firstOrFail();
        $transaction = KulCoinTransaction::query()->where('idempotency_key', 'seed-kc-gift-community-001')->firstOrFail();
        CommunityPostGift::query()->updateOrCreate(
            [
                'community_post_id' => $posts->get('welcome')->id,
                'sender_id' => $users->get('fan')->id,
                'kulcoin_transaction_id' => $transaction->id,
            ],
            [
                'recipient_user_id' => $users->get('creator')->id,
                'gift_id' => $gift->id,
                'quantity' => 1,
                'coin_amount' => 50,
                'message' => 'More studio posts, please!',
            ],
        );
    }
}
