<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RefreshFeedTrending extends Command
{
    protected $signature = 'feed:refresh-trending';

    protected $description = 'Refresh the shared Redis pool used for feed candidate ranking.';

    public function handle(): int
    {
        $key = (string) config('video.trending_pool_key', 'feed:trending:videos');
        $limit = max(1, (int) config('video.trending_pool_size', 500));

        try {
            $videos = Video::query()
                ->ready()
                ->where('visibility', 'public')
                ->select(['id', 'views_count', 'created_at'])
                ->orderByDesc('views_count')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $redis = Redis::connection();
            $redis->del($key);

            foreach ($videos as $video) {
                $ageHours = $video->created_at ? max(0, now()->diffInHours($video->created_at)) : 0;
                $score = (int) ($video->views_count ?? 0) + max(0, 1000 - $ageHours) / 10;
                $redis->zadd($key, $score, (string) $video->id);
            }

            $redis->expire($key, max(60, (int) config('video.trending_pool_ttl_seconds', 900)));
            $this->info(sprintf('Refreshed %d trending videos.', $videos->count()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Unable to refresh trending feed pool: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}