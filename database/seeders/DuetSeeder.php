<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DuetSeeder extends Seeder
{
    public const TARGET_COUNT = 200;

    public function run(): void
    {
        DB::transaction(function (): void {
            $creators = User::query()
                ->whereIn('username', [
                    'creator', 'zuri.moves', 'tunde.creates', 'naledi.fit',
                    'kwame.frames', 'amina.designs',
                ])
                ->orderBy('id')
                ->get()
                ->values();
            $sources = Video::query()
                ->whereNull('duet_source_video_id')
                ->where('allow_duet', true)
                ->where('visibility', 'public')
                ->where('status', 'ready')
                ->where('source_key', 'like', 'demo/%')
                ->orderBy('id')
                ->get()
                ->values();
            $viewers = User::query()
                ->whereIn('username', ['fan', 'fans'])
                ->orderBy('id')
                ->get()
                ->values();

            if ($creators->isEmpty() || $sources->isEmpty() || $viewers->isEmpty()) {
                throw new \RuntimeException('DuetSeeder requires the demo creators and ready duet-enabled videos.');
            }

            $layouts = ['side_by_side', 'picture_in_picture', 'top_and_bottom'];
            $reactions = [
                'Adding my own rhythm to this one.',
                'The original was too good not to answer.',
                'My take on today’s community prompt.',
                'Same idea, completely different energy.',
                'Passing this creative chain to the next person.',
            ];

            for ($number = 1; $number <= self::TARGET_COUNT; $number++) {
                $source = $sources->get(($number - 1) % $sources->count());
                $creator = $creators->get(($number - 1) % $creators->count());

                if ((int) $creator->id === (int) $source->user_id) {
                    $creator = $creators->get($number % $creators->count());
                }

                $layout = $layouts[($number - 1) % count($layouts)];
                $ageHours = ($number % 168) + 1;
                $sourceKey = sprintf('demo/duets/duet-%03d.mp4', $number);
                $video = Video::query()->updateOrCreate(
                    ['source_key' => $sourceKey],
                    [
                        'user_id' => $creator->id,
                        'duet_source_video_id' => $source->id,
                        'media_type' => 'video',
                        'purpose' => 'post_video',
                        'title' => sprintf('Community duet %03d', $number),
                        'caption' => $reactions[($number - 1) % count($reactions)].' #Duet #KulsahCreators',
                        'visibility' => 'public',
                        'allow_duet' => $number % 5 === 0,
                        'content_type' => $source->content_type,
                        'content_types' => array_values(array_unique(array_merge(
                            $source->content_types ?? [$source->content_type],
                            ['duet'],
                        ))),
                        'original_filename' => basename($sourceKey),
                        'mime_type' => 'video/mp4',
                        'source_disk' => 'remote-demo',
                        'source_bucket' => 'cloudinary-demo',
                        'source_url' => $source->source_url,
                        'upload_status' => 'uploaded',
                        'processing_status' => 'ready',
                        'cdn_url' => $source->cdn_url,
                        'rendered_url' => $source->rendered_url,
                        'streaming_url' => $source->streaming_url,
                        'playback_type' => 'progressive',
                        'fallback_mp4_url' => $source->fallback_mp4_url ?: $source->source_url,
                        'poster_url' => $source->poster_url,
                        'thumbnail_url' => $source->thumbnail_url ?: $source->poster_url,
                        'cloudinary_public_id' => $source->cloudinary_public_id,
                        'duration' => $source->duration,
                        'duration_ms' => $source->duration_ms,
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
                        'views_count' => 300 + (($number * 137) % 25000),
                        'metadata' => [
                            'seeded' => true,
                            'seed_group' => 'volume_duets',
                            'is_original' => false,
                            'is_duet' => true,
                            'duet_composition_id' => sprintf('demo-volume-duet-%03d', $number),
                            'duet_source_video_id' => $source->id,
                            'duet_source_user_id' => $source->user_id,
                            'duet_source_title' => $source->title,
                            'duet_layout' => $layout,
                            'duet_source_position' => $layout === 'top_and_bottom' ? 'top' : 'left',
                            'duet_response_position' => $layout === 'top_and_bottom' ? 'bottom' : 'right',
                            'duet_source_start_ms' => 0,
                            'duet_response_start_ms' => 0,
                            'duet_duration_ms' => $source->duration_ms,
                            'duet_source_volume' => 0.7,
                            'duet_response_volume' => 1.0,
                            'duet_render_status' => 'completed',
                            'shares_count' => 10 + ($number % 170),
                            'caption_hashtags' => ['#Duet', '#KulsahCreators'],
                            'caption_mentions' => [],
                        ],
                    ],
                );

                $createdAt = now()->subHours($ageHours);
                DB::table('videos')->where('id', $video->id)->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $viewer = $viewers->get(($number - 1) % $viewers->count());
                DB::table('video_likes')->updateOrInsert(
                    ['video_id' => $video->id, 'user_id' => $viewer->id],
                    ['created_at' => $createdAt->copy()->addHour(), 'updated_at' => $createdAt->copy()->addHour()],
                );
                DB::table('video_views')->updateOrInsert(
                    ['video_id' => $video->id, 'user_id' => $viewer->id],
                    [
                        'viewed_at' => $createdAt->copy()->addHours(2),
                        'created_at' => $createdAt->copy()->addHours(2),
                        'updated_at' => $createdAt->copy()->addHours(2),
                    ],
                );
            }
        });
    }
}
