<?php

namespace App\Jobs;

use App\Services\FastApiRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordRecommendationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 10;

    public function __construct(
        public readonly int $userId,
        public readonly string $eventType,
        public readonly ?int $videoId = null,
        public readonly float $value = 1.0,
        public readonly array $terms = [],
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(FastApiRecommendationService $service): void
    {
        $service->recordEvent(
            userId: $this->userId,
            eventType: $this->eventType,
            videoId: $this->videoId,
            value: $this->value,
            terms: $this->terms,
        );
    }
}
