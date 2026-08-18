<?php

namespace App\Domain\Challenges\Scoring;

final readonly class ScoreResult
{
    public function __construct(public string $rawValue, public array $metadata = []) {}
}
