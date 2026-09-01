<?php

namespace App\Domain\Challenges\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateChallenge
{
    public function execute(Challenge $challenge, User $actor, array $data): Challenge
    {
        return DB::transaction(function () use ($challenge, $actor, $data): Challenge {
            $challenge = Challenge::lockForUpdate()->findOrFail($challenge->id);
            if (! in_array($challenge->status, [ChallengeStatus::Draft, ChallengeStatus::Rejected], true)) {
                throw ValidationException::withMessages(['challenge' => 'Only draft or rejected challenges can be edited.']);
            } $before = $challenge->toArray();
            $challenge->fill(Arr::except($data, ['rules', 'media', 'prizes', 'scoring_components', 'jury_criteria', 'judging_stages']))->save();
            $versioned = array_key_exists('rules', $data) || array_key_exists('scoring_components', $data);
            if ($versioned) {
                $challenge->forceFill(['rules_version' => $challenge->rules_version + 1])->save();
            } if (array_key_exists('rules', $data)) {
                foreach ($data['rules'] as $item) {
                    $challenge->rules()->create($item + ['rules_version' => $challenge->rules_version]);
                }
            } if (array_key_exists('scoring_components', $data)) {
                foreach ($data['scoring_components'] as $item) {
                    $challenge->scoringComponents()->create($item + ['rules_version' => $challenge->rules_version]);
                }
            } foreach (['media', 'prizes', 'jury_criteria', 'judging_stages'] as $relation) {
                if (! array_key_exists($relation, $data)) {
                    continue;
                } $method = match ($relation) {
                    'jury_criteria' => 'juryCriteria', 'judging_stages' => 'stages', default => $relation
                };
                $challenge->{$method}()->delete();
                foreach ($data[$relation] as $item) {
                    $challenge->{$method}()->create($item);
                }
            } ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => 'challenge.updated', 'subject_type' => Challenge::class, 'subject_id' => $challenge->id, 'before' => $before, 'after' => $challenge->toArray()]);

            return $challenge->load(['prizes', 'rules', 'media.video', 'scoringComponents', 'juryCriteria']);
        });
    }
}
