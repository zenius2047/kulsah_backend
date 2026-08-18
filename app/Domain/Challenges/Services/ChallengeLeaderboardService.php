<?php

namespace App\Domain\Challenges\Services;

use App\Models\Challenge;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ChallengeLeaderboardService
{
    public function get(Challenge $challenge, int $perPage = 25): LengthAwarePaginator
    {
        return $challenge->entries()->where('status', 'approved')
            ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
            ->with(['creator', 'video'])
            ->orderByDesc('current_score')->orderBy('submitted_at')->orderBy('id')->paginate(min(100, max(1, $perPage)));
    }
}
