<?php

namespace App\Http\Requests\Api\V1\Video;

use Illuminate\Foundation\Http\FormRequest;

class VideoRenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'filters',
            'audio',
            'trim',
            'output',
            'assets',
            'canvas',
            'schemaVersion',
            'metadata',
            'scenes',
            'globalAudioTracks',
            'globalEffects',
            'guides',
            'layers',
        ] as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $this->merge([$key => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'schemaVersion' => ['sometimes', 'string', 'max:32'],
            'metadata' => ['sometimes', 'array'],
            'canvas' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'assets' => ['sometimes', 'array'],
            'scenes' => ['sometimes', 'array', 'min:1', 'max:100'],
            'globalAudioTracks' => ['sometimes', 'array'],
            'globalEffects' => ['sometimes', 'array'],
            'guides' => ['sometimes', 'array'],
            'layers' => ['sometimes', 'array', 'max:100'],
            'filters' => ['sometimes', 'array'],
            'trim' => ['sometimes', 'array'],
            'audio' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'assets' => ['sometimes', 'array'],
            'assets.*.file_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'assets.*.asset_file_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'assets.*.url' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'assets.*.asset_url' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'asset_files' => ['sometimes', 'array', 'max:30'],
            'asset_files.*' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
