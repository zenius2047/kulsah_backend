<?php

namespace App\Http\Resources;

use App\Domain\Challenges\Services\ChallengeEligibilityService;
use App\Enums\ChallengeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $entryCount = isset($this->entries_count) ? (int) $this->entries_count : $this->entries()->count();
        $participantCount = isset($this->participants_count) ? (int) $this->participants_count : $this->entries()->distinct()->count('creator_id');
        $hasJoined = $user ? $this->entries()->where('creator_id', $user->id)->exists() : false;
        $hasVoted = $user ? $this->ballots()->where('voter_id', $user->id)->exists() : false;
        $eligibility = $user ? app(ChallengeEligibilityService::class)->evaluate($this->resource, $user) : null;

        return [
            'id' => $this->id, 'title' => $this->title, 'slug' => $this->slug, 'description' => $this->description,
            'instructions' => $this->instructions, 'host_type' => $this->host_type->value, 'host_user_id' => $this->host_user_id,
            'host_organization_id' => $this->host_organization_id, 'visibility' => $this->visibility->value, 'status' => $this->status->value,
            'judging_strategy' => $this->judging_strategy->value, 'winner_selection_method' => $this->winner_selection_method,
            'schedule' => collect(['registration_starts_at', 'registration_ends_at', 'submission_starts_at', 'submission_ends_at', 'voting_starts_at', 'voting_ends_at', 'judging_starts_at', 'judging_ends_at', 'results_publish_at'])->mapWithKeys(fn ($key) => [$key => optional($this->{$key})?->toIso8601String()])->all(),
            'leaderboard' => ['enabled' => (bool) $this->show_leaderboard, 'mode' => $this->leaderboard_mode],
            'participant_count' => $participantCount, 'entry_count' => $entryCount,
            'current_phase' => $this->status->value, 'time_remaining_seconds' => $this->timeRemaining(),
            'can_join' => $user && $this->isAcceptingSubmissions() && ($eligibility['eligible'] ?? false) && ! ($this->max_entries_per_creator <= $this->entries()->where('creator_id', $user->id)->count()),
            'can_vote' => $user && $this->isVotingOpen(), 'has_user_joined' => $hasJoined, 'has_user_voted' => $hasVoted,
            'eligibility' => $eligibility, 'prizes' => ChallengePrizeResource::collection($this->whenLoaded('prizes')),
            'media' => ChallengeMediaResource::collection($this->whenLoaded('media')),
            'scoring_components' => $this->whenLoaded('scoringComponents'), 'jury_criteria' => $this->whenLoaded('juryCriteria'),
            'created_at' => optional($this->created_at)?->toIso8601String(), 'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function timeRemaining(): ?int
    {
        $target = match ($this->status) {
            ChallengeStatus::Active => $this->submission_ends_at,
            ChallengeStatus::SubmissionsClosed => $this->voting_ends_at,
            ChallengeStatus::Judging => $this->judging_ends_at,
            default => null,
        };

        return $target ? max(0, now()->diffInSeconds($target, false)) : null;
    }
}
