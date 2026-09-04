<?php

namespace App\Contracts;

use Illuminate\Http\Client\Response as HttpResponse;

interface MusicProviderInterface
{
    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function trending(array $filters = []): array;

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function search(array $filters = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function track(string $externalId, ?string $viewerToken = null): ?array;

    public function stream(string $externalId, ?string $viewerToken = null): HttpResponse;
}
