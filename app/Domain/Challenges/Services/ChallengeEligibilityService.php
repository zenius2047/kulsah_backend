<?php

namespace App\Domain\Challenges\Services;

use App\Enums\ChallengeVisibility;
use App\Models\Challenge;
use App\Models\ChallengeInvite;
use App\Models\ChallengeRule;
use App\Models\User;
use Carbon\Carbon;

class ChallengeEligibilityService
{
    public function __construct(private readonly ChallengeRuleEngine $rules) {}

    public function evaluate(Challenge $challenge, User $user): array
    {
        $failures = [];

        if ($challenge->visibility === ChallengeVisibility::InviteOnly) {
            $invite = ChallengeInvite::query()->where('challenge_id', $challenge->id)
                ->where('invited_user_id', $user->id)->whereIn('status', ['pending', 'accepted'])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            if (! $invite) {
                $failures[] = ['rule' => 'invite_only', 'required' => true, 'actual' => false];
            }
        }

        foreach ($challenge->rules()->where('scope', 'eligibility')->where('rules_version', $challenge->rules_version)->get() as $rule) {
            $actual = $this->actualValue($rule, $user, $challenge);
            if (! $this->rules->passes($rule, $actual)) {
                $failures[] = ['rule' => $rule->rule_type, 'operator' => $rule->operator, 'required' => $rule->value, 'actual' => $actual];
            }
        }

        return ['eligible' => $failures === [], 'failures' => $failures, 'evaluated_at' => now()->toIso8601String()];
    }

    private function actualValue(ChallengeRule $rule, User $user, Challenge $challenge): mixed
    {
        return match ($rule->rule_type) {
            'everyone' => true,
            'verified_creators_only', 'verified_only' => (bool) $user->verified,
            'minimum_followers', 'maximum_followers' => $user->followers()->count(),
            'country' => data_get($user->onboarding, 'country') ?? $user->location,
            'region' => data_get($user->onboarding, 'region'),
            'creator_category' => data_get($user->onboarding, 'category'),
            'account_age' => $user->created_at ? $user->created_at->diffInDays(now()) : 0,
            'minimum_age' => $user->dob ? Carbon::parse($user->dob)->age : null,
            'specific_creators' => $user->id,
            'followers_only' => $challenge->host_user_id ? $user->follows()->where('followed_id', $challenge->host_user_id)->exists() : false,
            'previous_challenge_winners' => \App\Models\ChallengeWinner::query()->whereHas('entry', fn ($query) => $query->where('creator_id', $user->id))->exists(),
            'not_previous_winners' => ! \App\Models\ChallengeWinner::query()->whereHas('entry', fn ($query) => $query->where('creator_id', $user->id))->exists(),
            default => data_get($user, $rule->rule_type),
        };
    }
}
