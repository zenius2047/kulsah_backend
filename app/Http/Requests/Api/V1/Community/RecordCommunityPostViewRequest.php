<?php

namespace App\Http\Requests\Api\V1\Community;

use Illuminate\Foundation\Http\FormRequest;

class RecordCommunityPostViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'watch_duration_seconds' => ['sometimes', 'numeric', 'min:0', 'max:'.config('community.views.maximum_watch_seconds', 14400)],
            'completion_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'completed' => ['sometimes', 'boolean'],
            'visible_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'visible_duration_seconds' => ['sometimes', 'numeric', 'min:0', 'max:300'],
        ];
    }
}
