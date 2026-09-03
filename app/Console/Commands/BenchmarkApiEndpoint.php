<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class BenchmarkApiEndpoint extends Command
{
    protected $signature = 'api:benchmark
        {path=/api/v1/general/feed : Internal API path to benchmark}
        {--method=GET : HTTP method}
        {--iterations=5 : Number of measured requests}
        {--warmup=1 : Number of warmup requests}';

    protected $description = 'Measure API latency, SQL query count, and memory usage.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $method = strtoupper((string) $this->option('method'));
        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));

        for ($index = 0; $index < $warmup; $index++) {
            $this->sendRequest($method, $path);
        }

        $samples = [];
        $statusCodes = [];
        $queryCounts = [];
        $queries = 0;

        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries++;
        });

        for ($index = 0; $index < $iterations; $index++) {
            $queries = 0;
            $startedAt = hrtime(true);
            $memoryBefore = memory_get_usage(true);

            try {
                $response = $this->sendRequest($method, $path);
                $statusCodes[] = $response->getStatusCode();
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());
                return self::FAILURE;
            }

            $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
            $queryCounts[] = $queries;
            $this->line(sprintf(
                '#%d %d %.2f ms %d queries %s',
                $index + 1,
                $statusCodes[array_key_last($statusCodes)],
                $samples[array_key_last($samples)],
                $queries,
                $this->formatBytes(memory_get_usage(true) - $memoryBefore)
            ));
        }

        sort($samples);
        $average = array_sum($samples) / count($samples);
        $p95Index = min(count($samples) - 1, (int) ceil(count($samples) * 0.95) - 1);

        $this->newLine();
        $this->info('Benchmark summary');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Path', $path],
                ['Method', $method],
                ['Iterations', (string) $iterations],
                ['Statuses', implode(', ', array_unique($statusCodes))],
                ['Average latency', number_format($average, 2).' ms'],
                ['P95 latency', number_format($samples[$p95Index], 2).' ms'],
                ['Average queries', number_format(array_sum($queryCounts) / count($queryCounts), 2)],
                ['Peak memory', $this->formatBytes(memory_get_peak_usage(true))],
            ]
        );

        return self::SUCCESS;
    }

    private function sendRequest(string $method, string $path)
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BENCHMARK' => '1',
        ]);

        return app()->handle($request);
    }

    private function formatBytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2).' MB';
    }
}