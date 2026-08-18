<?php

namespace App\Jobs;

use App\Models\ChallengeMedia;
use App\Services\CloudinaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractChallengeCoverFrame implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly int $challengeMediaId) {}

    public function handle(CloudinaryService $cloudinary): void
    {
        $media = ChallengeMedia::with('video')->find($this->challengeMediaId);
        if (! $media || data_get($media->metadata, 'cover_source') !== 'video') {
            return;
        }
        if ($media->video?->processing_status?->value !== 'ready' || ! $media->video?->cloudinary_public_id) {
            $this->release(60);

            return;
        }

        $frameTimeMs = max(0, (int) data_get($media->metadata, 'cover_frame_time_ms', 0));
        $media->update(['metadata' => array_merge($media->metadata ?? [], [
            'cover_url' => $cloudinary->generatePosterAtTimeUrl($media->video->cloudinary_public_id, $frameTimeMs),
            'cover_extracted_at' => now()->toIso8601String(),
        ])]);
    }
}
