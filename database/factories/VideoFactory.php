<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => fake()->sentence(), 'caption' => '#challenge', 'source_url' => 'https://example.test/video.mp4', 'source_key' => 'videos/'.fake()->uuid().'.mp4', 'source_disk' => 'local', 'upload_status' => 'uploaded', 'processing_status' => 'ready', 'playback_type' => 'hls', 'hls_url' => 'https://cdn.example.test/video.m3u8', 'streaming_url' => 'https://cdn.example.test/video.m3u8', 'cdn_url' => 'https://cdn.example.test/video.m3u8', 'duration' => 30, 'duration_ms' => 30000, 'status' => 'ready', 'metadata' => ['aspect_ratio' => '9:16']];
    }
}
