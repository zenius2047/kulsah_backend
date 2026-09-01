<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoMentionedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'fan', 'fans', 'creator', 'zuri.moves', 'tunde.creates',
            ])->get()->keyBy('username');
            $videos = Video::query()->whereIn('source_key', [
                'demo/coastal-dance.mp4',
                'demo/dog-reaction.mp4',
            ])->get()->keyBy('source_key');

            $notifications = [
                ['44000000-0000-4000-8000-000000000001', 'fan', 'zuri.moves', 'demo/coastal-dance.mp4', ['fan'], ['#CoastalMoves'], null, 2],
                ['44000000-0000-4000-8000-000000000002', 'fans', 'tunde.creates', 'demo/dog-reaction.mp4', ['fans'], ['#Comedy'], null, 5],
                ['44000000-0000-4000-8000-000000000003', 'creator', 'zuri.moves', 'demo/coastal-dance.mp4', ['creator'], ['#DanceAfrica'], now()->subHours(4), 9],
            ];

            foreach ($notifications as [$id, $recipientUsername, $actorUsername, $sourceKey, $mentions, $hashtags, $readAt, $ageHours]) {
                $recipient = $users->get($recipientUsername);
                $actor = $users->get($actorUsername);
                $video = $videos->get($sourceKey);

                DB::table('notifications')->updateOrInsert(
                    ['id' => $id],
                    [
                        'type' => VideoMentionedNotification::class,
                        'notifiable_type' => User::class,
                        'notifiable_id' => $recipient->id,
                        'data' => json_encode([
                            'type' => 'video.mentioned',
                            'video_id' => $video->id,
                            'video_title' => $video->title,
                            'caption' => $video->caption,
                            'content_type' => $video->content_type,
                            'mentioned_by' => [
                                'id' => $actor->id,
                                'name' => $actor->name,
                                'username' => $actor->username,
                            ],
                            'mentions' => $mentions,
                            'hashtags' => $hashtags,
                            'notified_user_id' => $recipient->id,
                            'created_at' => now()->subHours($ageHours)->toIso8601String(),
                            'seeded' => true,
                        ], JSON_UNESCAPED_SLASHES),
                        'read_at' => $readAt,
                        'created_at' => now()->subHours($ageHours),
                        'updated_at' => $readAt ?? now()->subHours($ageHours),
                    ],
                );
            }
        });
    }
}
