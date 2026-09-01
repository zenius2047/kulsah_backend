<?php

namespace App\Http\Requests\Api\V1\Challenge;

use Illuminate\Foundation\Http\FormRequest;

class SubmitJuryScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['scores' => ['required', 'array', 'min:1'], 'scores.*.criterion_id' => ['required', 'integer'], 'scores.*.score' => ['required', 'numeric'], 'scores.*.comment' => ['nullable', 'string', 'max:2000']];
    }
}
