<?php

namespace App\Domain\Challenges\Actions;

use App\Enums\ChallengeHostType;
use App\Enums\ChallengeStatus;
use App\Jobs\ExtractChallengeCoverFrame;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateChallenge
{
    public function execute(User $user, array $data, ChallengeStatus $status = ChallengeStatus::Draft): Challenge
    {
        return DB::transaction(function () use ($user, $data, $status): Challenge {
            $challenge = new Challenge(Arr::except($data, ['rules', 'media', 'sponsors', 'reward_pools', 'prizes', 'judging_stages', 'scoring_components', 'jury_criteria']));
            $challenge->forceFill([
                'created_by_user_id' => $user->id,
                'host_type' => $data['host_type'] ?? ChallengeHostType::Creator,
                'host_user_id' => ($data['host_type'] ?? 'creator') === 'creator' ? $user->id : ($data['host_user_id'] ?? null),
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']),
                'status' => $status,
            ])->save();

            foreach ($data['rules'] ?? [] as $item) {
                $challenge->rules()->create($item + ['rules_version' => 1]);
            }
            foreach ($data['media'] ?? [] as $item) {
                $media = $challenge->media()->create($item);
                if (data_get($media->metadata, 'cover_source') === 'video') {
                    DB::afterCommit(fn () => ExtractChallengeCoverFrame::dispatch($media->id));
                }
            }
            foreach ($data['sponsors'] ?? [] as $item) {
                $challenge->sponsors()->create($item);
            }
            foreach ($data['reward_pools'] ?? [] as $item) {
                $challenge->rewardPools()->create($item);
            }
            foreach ($data['prizes'] ?? [] as $item) {
                $challenge->prizes()->create($item);
            }
            foreach ($data['judging_stages'] ?? [] as $item) {
                $challenge->stages()->create($item);
            }
            foreach ($data['scoring_components'] ?? [] as $item) {
                $challenge->scoringComponents()->create($item + ['rules_version' => 1]);
            }
            foreach ($data['jury_criteria'] ?? [] as $item) {
                $challenge->juryCriteria()->create($item);
            }
            $challenge->collaborators()->create(['user_id' => $user->id, 'role' => 'owner', 'invited_by_user_id' => $user->id, 'status' => 'accepted', 'accepted_at' => now()]);
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $user->id, 'action' => 'challenge.created', 'subject_type' => Challenge::class, 'subject_id' => $challenge->id, 'after' => $challenge->toArray()]);

            return $challenge->load(['creator', 'prizes', 'rules', 'media.video', 'scoringComponents', 'juryCriteria']);
        });
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: Str::random(10);
        $slug = $base;
        for ($i = 2; Challenge::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
