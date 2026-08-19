<?php

namespace App\Http\Requests\Api\V1\Challenge;

use Illuminate\Foundation\Http\FormRequest;

class CastChallengeBallotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'choices' => ['required', 'array', 'min:1', 'max:100'],
            'choices.*.challenge_entry_id' => ['required', 'integer'],
            'choices.*.rank' => ['nullable', 'integer', 'min:1'],
            'choices.*.points' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}