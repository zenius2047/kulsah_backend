<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VideoLifecycleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $users = User::query()->whereIn('username', [
                'creator', 'zuri.moves', 'tunde.creates',
            ])->get()->keyBy('username');
            $media = DemoMedia::videos();

            $videos = [
                [
                    'source_key' => 'demo/lifecycle/upload-in-progress.mp4',
                    'user_id' => $users->get('creator')->id,
                    'title' => 'Studio transition draft',
                    'caption' => 'Uploading a new transition breakdown.',
                    'content_type' => 'dance',
                    'content_types' => ['dance', 'education'],
                    'source_url' => null,
                    'upload_status' => 'uploading',
                    'processing_status' => 'initialized',
                    'status' => 'processing',
                    'render_status' => null,
                    'progress_percentage' => 42,
                    'processing_error' => null,
                    'metadata' => ['seeded' => true, 'upload_state' => 'uploading', 'requires_editing' => true],
                ],
                [
                    'source_key' => 'demo/lifecycle/transcoding.mp4',
                    'user_id' => $users->get('zuri.moves')->id,
                    'title' => 'Rehearsal recap',
                    'caption' => 'Processing the full rehearsal recap now.',
                    'content_type' => 'dance',
                    'content_types' => ['dance', 'behind_the_scenes'],
                    'source_url' => $media['zuri-warmup']['video_url'],
                    'upload_status' => 'uploaded',
                    'processing_status' => 'processing',
                    'status' => 'processing',
                    'render_status' => 'processing',
                    'progress_percentage' => 76,
                    'processing_error' => null,
                    'metadata' => ['seeded' => true, 'upload_state' => 'uploaded', 'processing_state' => 'transcoding'],
                ],
                [
                    'source_key' => 'demo/lifecycle/processing-failed.mp4',
                    'user_id' => $users->get('tunde.creates')->id,
                    'title' => 'Night shoot outtake',
                    'caption' => 'This upload is intentionally failed so retry UI can be tested.',
                    'content_type' => 'comedy',
                    'content_types' => ['comedy', 'behind_the_scenes'],
                    'source_url' => $media['tunde-behind-scenes']['video_url'],
                    'upload_status' => 'uploaded',
                    'processing_status' => 'processing_failed',
                    'status' => 'failed',
                    'render_status' => 'failed',
                    'progress_percentage' => 100,
                    'processing_error' => 'Demo transcode failure: unsupported source audio profile.',
                    'metadata' => ['seeded' => true, 'upload_state' => 'uploaded', 'processing_state' => 'failed', 'retryable' => true],
                ],
            ];

            foreach ($videos as $index => $attributes) {
                Video::query()->updateOrCreate(
                    ['source_key' => $attributes['source_key']],
                    $attributes + [
                        'media_type' => 'video',
                        'purpose' => 'post_video',
                        'visibility' => 'public',
                        'allow_duet' => false,
                        'original_filename' => basename($attributes['source_key']),
                        'mime_type' => 'video/mp4',
                        'source_disk' => 'remote-demo',
                        'source_bucket' => 'cloudinary-demo',
                        'uploaded_at' => $attributes['upload_status'] === 'uploaded' ? now()->subHours($index + 2) : null,
                        'processing_started_at' => $attributes['processing_status'] !== 'initialized' ? now()->subHours($index + 1) : null,
                        'failed_at' => $attributes['status'] === 'failed' ? now()->subHour() : null,
                        'views_count' => 0,
                    ],
                );
            }
        });
    }
}
