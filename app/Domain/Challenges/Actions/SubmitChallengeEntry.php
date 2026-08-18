<?php

namespace App\Domain\Challenges\Actions;

use App\Domain\Challenges\Services\ChallengeEligibilityService;
use App\Domain\Challenges\Services\ChallengeRuleEngine;
use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeEntry;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitChallengeEntry
{
    public function __construct(private readonly ChallengeEligibilityService $eligibility) {}

    public function execute(Challenge $challenge, User $user, array $data): ChallengeEntry
    {
        return DB::transaction(function () use ($challenge, $user, $data): ChallengeEntry {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            if (! $challenge->isAcceptingSubmissions()) {
                throw ValidationException::withMessages(['challenge' => 'This challenge is not accepting submissions.']);
            }
            $video = Video::query()->whereKey($data['video_id'])->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $video) {
                throw ValidationException::withMessages(['video_id' => 'The selected video does not belong to you.']);
            }
            if ($video->status !== 'ready' || $video->processing_status?->value !== 'ready' || ! $video->hls_url) {
                throw ValidationException::withMessages(['video_id' => 'The selected video has not finished HLS processing.']);
            }

            $existing = ChallengeEntry::query()->where('challenge_id', $challenge->id)->where('creator_id', $user->id)->count();
            if ($existing >= $challenge->max_entries_per_creator) {
                throw ValidationException::withMessages(['video_id' => 'You have reached the entry limit for this challenge.']);
            }
            if ($challenge->max_participants && ! ChallengeEntry::where('challenge_id', $challenge->id)->where('creator_id', $user->id)->exists()
                && ChallengeEntry::where('challenge_id', $challenge->id)->distinct()->count('creator_id') >= $challenge->max_participants) {
                throw ValidationException::withMessages(['challenge' => 'This challenge has reached its participant limit.']);
            }

            $evaluation = $this->eligibility->evaluate($challenge, $user);
            if (! $evaluation['eligible']) {
                throw ValidationException::withMessages(['eligibility' => ['You are not eligible for this challenge.', ...array_map(fn ($f) => $f['rule'], $evaluation['failures'])]]);
            }
            $this->validateSubmissionRules($challenge, $video);

            $entry = ChallengeEntry::create([
                'challenge_id' => $challenge->id, 'creator_id' => $user->id, 'video_id' => $video->id,
                'submission_number' => $existing + 1, 'caption' => $data['caption'] ?? $video->caption,
                'status' => 'approved', 'moderation_status' => 'approved', 'eligibility_status' => 'eligible',
                'submitted_at' => now(), 'approved_at' => now(),
            ]);
            $entry->eligibilitySnapshots()->create(['rules_version' => $challenge->rules_version, 'eligible' => true, 'evaluation' => $evaluation, 'evaluated_at' => now()]);
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $user->id, 'action' => 'entry.submitted', 'subject_type' => ChallengeEntry::class, 'subject_id' => $entry->id, 'after' => $entry->toArray()]);

            return $entry->load(['creator', 'video', 'eligibilitySnapshots']);
        });
    }

    private function validateSubmissionRules(Challenge $challenge, Video $video): void
    {
        foreach ($challenge->rules()->where('scope', 'submission')->where('rules_version', $challenge->rules_version)->get() as $rule) {
            $error = match ($rule->rule_type) {
                'video_duration' => ! app(ChallengeRuleEngine::class)->passes($rule, $video->duration),
                'aspect_ratio' => ! app(ChallengeRuleEngine::class)->passes($rule, data_get($video->metadata, 'aspect_ratio', data_get($video->metadata, 'canvas.aspect_ratio'))),
                'required_hashtag' => ! str_contains(strtolower((string) $video->caption), strtolower((string) $rule->value)),
                'official_sound' => (int) data_get($video->metadata, 'sound_id') !== (int) $challenge->official_sound_id,
                default => false,
            };
            if ($error) {
                throw ValidationException::withMessages(['video_id' => "The video failed the {$rule->rule_type} rule."]);
            }
        }
    }
}
