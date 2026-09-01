<?php

namespace App\Http\Requests\Api\V1\Challenge;

use Illuminate\Foundation\Http\FormRequest;

class SubmitChallengeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['video_id' => ['required', 'integer', 'exists:videos,id'], 'caption' => ['nullable', 'string', 'max:5000']];
    }
}
