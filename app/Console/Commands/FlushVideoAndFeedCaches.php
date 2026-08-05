<?php

namespace App\Console\Commands;

use App\Services\FeedService;
use App\Services\VideoCacheService;
use Illuminate\Console\Command;

class FlushVideoAndFeedCaches extends Command
{
    protected $signature = 'cache:flush-video-feed {--videos : Flush video-related caches} {--feed : Flush feed caches} {--all : Flush both cache groups}';

    protected $description = 'Flush the Redis cache groups used by videos and feeds.';

    public function __construct(
        private readonly VideoCacheService $videoCacheService,
        private readonly FeedService $feedService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $flushVideos = $this->option('all') || $this->option('videos') || (! $this->option('feed'));
        $flushFeed = $this->option('all') || $this->option('feed') || (! $this->option('videos'));

        if ($flushVideos) {
            $this->videoCacheService->flushAllVideoCaches();
            $this->info('Flushed video cache group.');
        }

        if ($flushFeed) {
            $this->feedService->flushFeedCaches();
            $this->info('Flushed feed cache group.');
        }

        return self::SUCCESS;
    }
}
