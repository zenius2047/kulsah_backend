<?php

namespace App\Http\Requests\Api\V1\Challenge;

class UpdateChallengeRequest extends StoreChallengeRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_values(array_filter($rules, fn ($rule) => $rule !== 'required')))->all();
    }
}
