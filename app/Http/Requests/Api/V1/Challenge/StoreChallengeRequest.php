<?php

namespace App\Http\Requests\Api\V1\Challenge;

use App\Enums\ChallengeHostType;
use App\Enums\ChallengeJudgingStrategy;
use App\Enums\ChallengeRewardType;
use App\Enums\ChallengeVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'], 'instructions' => ['nullable', 'string', 'max:20000'],
            'host_type' => ['sometimes', Rule::enum(ChallengeHostType::class)],
            'host_user_id' => ['nullable', 'integer', 'exists:users,id'], 'host_organization_id' => ['nullable', 'integer'],
            'visibility' => ['required', Rule::enum(ChallengeVisibility::class)],
            'judging_strategy' => ['required', Rule::enum(ChallengeJudgingStrategy::class)],
            'winner_selection_method' => ['required', Rule::in(['automatic_score', 'jury_score', 'host_selection', 'hybrid', 'manual_admin'])],
            'submission_starts_at' => ['required', 'date'], 'submission_ends_at' => ['required', 'date', 'after:submission_starts_at'],
            'registration_starts_at' => ['nullable', 'date'], 'registration_ends_at' => ['nullable', 'date', 'after:registration_starts_at'],
            'voting_starts_at' => ['nullable', 'date'], 'voting_ends_at' => ['nullable', 'required_with:voting_starts_at', 'date', 'after:voting_starts_at'],
            'judging_starts_at' => ['nullable', 'date'], 'judging_ends_at' => ['nullable', 'required_with:judging_starts_at', 'date', 'after:judging_starts_at'],
            'results_publish_at' => ['nullable', 'date', 'after_or_equal:judging_ends_at'],
            'show_leaderboard' => ['sometimes', 'boolean'], 'leaderboard_mode' => ['sometimes', Rule::in(['live', 'delayed', 'hidden', 'final_only'])],
            'max_participants' => ['nullable', 'integer', 'min:1'], 'max_entries_per_creator' => ['required', 'integer', 'min:1', 'max:100'],
            'hashtag' => ['nullable', 'string', 'max:100'], 'official_sound_id' => ['nullable', 'integer', 'exists:videos,id'],
            'voting_configuration' => ['nullable', 'array'], 'voting_configuration.mode' => ['required_with:voting_configuration', Rule::in(['single_choice', 'multiple_choice', 'ranked_choice', 'points_allocation'])],
            'voting_configuration.allow_self_voting' => ['sometimes', 'boolean'], 'voting_configuration.allow_vote_changes' => ['sometimes', 'boolean'],
            'voting_configuration.maximum_choices' => ['sometimes', 'integer', 'min:1', 'max:100'], 'voting_configuration.rank_points' => ['sometimes', 'array'],
            'integrity_configuration' => ['nullable', 'array'], 'rules' => ['sometimes', 'array', 'max:100'],
            'rules.*.scope' => ['required', Rule::in(['eligibility', 'submission', 'voting', 'judging', 'content', 'location', 'audience'])],
            'rules.*.rule_type' => ['required', 'string', 'max:64'], 'rules.*.operator' => ['required', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT_IN', 'BETWEEN'])],
            'rules.*.value' => ['present'], 'rules.*.is_required' => ['sometimes', 'boolean'],
            'media' => ['sometimes', 'array', 'max:20'], 'media.*.video_id' => ['required', 'integer', 'exists:videos,id'],
            'media.*.role' => ['required', Rule::in(['cover', 'challenge_video', 'instruction_video', 'sponsor_asset', 'banner', 'reference'])],
            'media.*.sort_order' => ['sometimes', 'integer', 'min:0'], 'media.*.metadata' => ['sometimes', 'array'],
            'media.*.uri' => ['prohibited'], 'media.*.metadata.uri' => ['prohibited'],
            'prizes' => ['required', 'array', 'min:1', 'max:100'], 'prizes.*.rank_from' => ['required', 'integer', 'min:1'],
            'prizes.*.rank_to' => ['required', 'integer', 'min:1'], 'prizes.*.reward_type' => ['required', Rule::enum(ChallengeRewardType::class)],
            'prizes.*.title' => ['required', 'string', 'max:255'], 'prizes.*.description' => ['nullable', 'string'],
            'prizes.*.currency' => ['nullable', 'string', 'size:3'], 'prizes.*.amount' => ['nullable', 'decimal:0,4', 'gt:0'],
            'prizes.*.quantity' => ['nullable', 'integer', 'min:1'], 'prizes.*.metadata' => ['sometimes', 'array'],
            'scoring_components' => ['required', 'array', 'min:1'], 'scoring_components.*.type' => ['required', Rule::in(['public_votes', 'reactions', 'views', 'shares', 'jury_score', 'host_score', 'completion_rate', 'engagement', 'custom_metric'])],
            'scoring_components.*.weight_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'scoring_components.*.point_value' => ['nullable', 'decimal:0,4', 'min:0'],
            'scoring_components.*.normalization_method' => ['nullable', Rule::in(['max_ratio', 'fixed_100'])], 'scoring_components.*.configuration' => ['sometimes', 'array'],
            'jury_criteria' => ['sometimes', 'array'], 'jury_criteria.*.name' => ['required', 'string'], 'jury_criteria.*.min_score' => ['sometimes', 'numeric'],
            'jury_criteria.*.max_score' => ['required', 'numeric', 'gt:0'], 'jury_criteria.*.weight_bps' => ['required', 'integer', 'min:1', 'max:10000'],
            'jury_criteria.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'judging_stages' => ['sometimes', 'array'], 'judging_stages.*.sequence' => ['required', 'integer', 'min:1'],
            'judging_stages.*.name' => ['required', 'string'], 'judging_stages.*.stage_type' => ['required', Rule::in(['submission', 'public_voting', 'jury', 'host_selection', 'integrity_review', 'final'])],
            'judging_stages.*.starts_at' => ['nullable', 'date'], 'judging_stages.*.ends_at' => ['nullable', 'date'], 'judging_stages.*.configuration' => ['sometimes', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('host_type', 'creator') !== 'creator' && ! $this->user()?->roles()->where('name', 'admin')->exists()) {
                $validator->errors()->add('host_type', 'Only platform administrators may create brand or platform-hosted challenges until organization ownership is available.');
            }
            if ($this->input('judging_strategy') === 'weighted_normalized' && collect($this->input('scoring_components', []))->sum('weight_bps') !== 10000) {
                $validator->errors()->add('scoring_components', 'Enabled scoring component weights must total 10000 basis points.');
            }
            $criteria = collect($this->input('jury_criteria', []));
            if ($criteria->isNotEmpty() && $criteria->sum('weight_bps') !== 10000) {
                $validator->errors()->add('jury_criteria', 'Jury criterion weights must total 10000 basis points.');
            }
            foreach ($this->input('prizes', []) as $index => $prize) {
                if (($prize['rank_from'] ?? 1) > ($prize['rank_to'] ?? 0)) {
                    $validator->errors()->add("prizes.{$index}.rank_to", 'Rank to must be at least rank from.');
                }
                if (in_array($prize['reward_type'] ?? null, ['cash', 'wallet_credit'], true) && (empty($prize['currency']) || empty($prize['amount']))) {
                    $validator->errors()->add("prizes.{$index}.amount", 'Cash and wallet prizes require currency and amount.');
                }
            }
            foreach ($this->input('media', []) as $index => $media) {
                if (str_contains(strtolower(json_encode($media) ?: ''), 'file:///')) {
                    $validator->errors()->add('media.'.$index, 'Local file URIs cannot be persisted as challenge media.');
                }
                if (! $this->user()?->videos()->whereKey($media['video_id'] ?? null)->exists()) {
                    $validator->errors()->add("media.{$index}.video_id", 'Challenge media must belong to the authenticated creator.');
                }
            }
        }];
    }
}
