<?php

namespace App\Http\Requests\Api\V1\Cloudinary;

use Illuminate\Foundation\Http\FormRequest;

class CloudinaryWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'nullable', 'string'],
            'notification_id' => ['sometimes', 'nullable', 'string'],
            'batch_id' => ['sometimes', 'nullable', 'string'],
            'request_id' => ['sometimes', 'nullable', 'string'],
            'public_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string'],
            'eager' => ['sometimes', 'array'],
        ];
    }
}
