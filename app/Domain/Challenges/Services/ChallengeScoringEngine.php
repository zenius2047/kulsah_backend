<?php

namespace App\Domain\Challenges\Services;

use App\Enums\ChallengeJudgingStrategy;
use App\Models\Challenge;
use App\Models\ChallengeBallotChoice;
use App\Models\ChallengeEntry;
use App\Models\ChallengeEntryScore;
use App\Models\ChallengeJuryScore;
use App\Models\ChallengeScoreSnapshot;
use App\Models\ChallengeScoringComponent;
use App\Models\VideoLike;
use App\Models\VideoView;
use Illuminate\Support\Facades\DB;

class ChallengeScoringEngine
{
    public function recalculate(ChallengeEntry $entry, string $reason = 'recalculation'): ChallengeEntry
    {
        return DB::transaction(function () use ($entry, $reason): ChallengeEntry {
            $entry = ChallengeEntry::with(['challenge.scoringComponents', 'video'])->lockForUpdate()->findOrFail($entry->id);
            $challenge = $entry->challenge;
            if ($entry->video?->processing_status?->value !== 'ready' || ! $entry->video?->hls_url) {
                $entry->forceFill(['current_score' => 0, 'current_rank' => null])->save();

                return $entry->refresh();
            }
            $details = [];
            $total = 0.0;

            foreach ($challenge->scoringComponents->where('enabled', true) as $component) {
                $raw = $this->rawValue($challenge, $entry, $component);
                $normalized = $challenge->judging_strategy === ChallengeJudgingStrategy::WeightedNormalized ? $this->normalize($challenge, $component, $raw) : $raw;
                $weighted = $challenge->judging_strategy === ChallengeJudgingStrategy::Points
                    ? $raw * (float) ($component->point_value ?? 1)
                    : $normalized * ((int) $component->weight_bps / 10000);
                $raw = round($raw, 6);
                $normalized = round($normalized, 6);
                $weighted = round($weighted, 6);
                ChallengeEntryScore::updateOrCreate(
                    ['challenge_entry_id' => $entry->id, 'scoring_component_id' => $component->id],
                    ['challenge_id' => $challenge->id, 'raw_value' => $raw, 'normalized_value' => $normalized, 'weighted_value' => $weighted, 'calculated_at' => now(), 'metadata' => ['normalization' => $component->normalization_method ?? 'max_ratio']]
                );
                $details[$component->type] = compact('raw', 'normalized', 'weighted');
                $total += $weighted;
            }

            $entry->forceFill(['current_score' => round($total, 6)])->save();
            ChallengeScoreSnapshot::create(['challenge_id' => $challenge->id, 'challenge_entry_id' => $entry->id, 'final_score' => round($total, 6), 'rank' => $entry->current_rank, 'components' => $details, 'reason' => $reason, 'captured_at' => now()]);

            return $entry->refresh();
        });
    }

    private function rawValue(Challenge $challenge, ChallengeEntry $entry, ChallengeScoringComponent $component): float
    {
        return match ($component->type) {
            'public_votes' => $this->voteValue($challenge, $entry),
            'reactions' => (float) VideoLike::where('video_id', $entry->video_id)->when($challenge->voting_starts_at, fn ($q) => $q->where('created_at', '>=', $challenge->voting_starts_at))->when($challenge->voting_ends_at, fn ($q) => $q->where('created_at', '<=', $challenge->voting_ends_at))->count(),
            'views' => (float) VideoView::where('video_id', $entry->video_id)->when($challenge->voting_starts_at, fn ($q) => $q->where('viewed_at', '>=', $challenge->voting_starts_at))->when($challenge->voting_ends_at, fn ($q) => $q->where('viewed_at', '<=', $challenge->voting_ends_at))->count(),
            'jury_score' => $this->juryValue($challenge, $entry),
            'shares', 'host_score', 'completion_rate', 'engagement', 'custom_metric' => (float) data_get($component->configuration, "entry_values.{$entry->id}", 0),
            default => 0.0,
        };
    }

    private function voteValue(Challenge $challenge, ChallengeEntry $entry): float
    {
        $query = ChallengeBallotChoice::where('challenge_entry_id', $entry->id)->whereHas('ballot', fn ($q) => $q->where('status', 'submitted'));
        $mode = data_get($challenge->voting_configuration, 'mode', 'single_choice');
        if ($mode === 'ranked_choice') {
            $rankPoints = data_get($challenge->voting_configuration, 'rank_points', []);

            return (float) $query->get()->sum(fn ($choice) => $rankPoints[(string) $choice->rank] ?? max(0, count($rankPoints) - $choice->rank + 1));
        }
        if ($mode === 'points_allocation') {
            return (float) $query->sum('points');
        }

        return (float) $query->count();
    }

    private function juryValue(Challenge $challenge, ChallengeEntry $entry): float
    {
        $criteria = $challenge->juryCriteria()->get()->keyBy('id');
        $memberScores = ChallengeJuryScore::where('challenge_entry_id', $entry->id)->with('juryMember')->get()->groupBy('jury_member_id');
        if ($memberScores->isEmpty() || $criteria->isEmpty()) {
            return 0.0;
        }
        $weightedMembers = 0.0;
        $weightTotal = 0;
        foreach ($memberScores as $scores) {
            $memberValue = 0.0;
            foreach ($scores as $score) {
                $criterion = $criteria->get($score->criterion_id);
                if ($criterion) {
                    $memberValue += (($score->score - $criterion->min_score) / max(1, $criterion->max_score - $criterion->min_score)) * 100 * ($criterion->weight_bps / 10000);
                }
            }
            $memberWeight = (int) ($scores->first()->juryMember?->weight_bps ?? 10000);
            $weightedMembers += $memberValue * $memberWeight;
            $weightTotal += $memberWeight;
        }

        return $weightTotal ? $weightedMembers / $weightTotal : 0.0;
    }

    private function normalize(Challenge $challenge, ChallengeScoringComponent $component, float $raw): float
    {
        if ($component->type === 'jury_score') {
            return max(0, min(100, $raw));
        }
        $max = ChallengeEntryScore::where('challenge_id', $challenge->id)->where('scoring_component_id', $component->id)->max('raw_value');
        $max = max($raw, (float) $max);

        return $max > 0 ? ($raw / $max) * 100 : 0.0;
    }
}
