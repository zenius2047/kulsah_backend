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
        foreach (['layers', 'filters', 'audio', 'trim', 'output', 'overlays'] as $key) {
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
            'layers' => ['sometimes', 'array', 'min:1', 'max:30'],
            'layers.*' => ['required', 'array'],
            'layers.*.type' => ['required', 'string', 'in:text,drawing,image,sticker,watermark,audio'],
            'layers.*.text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'layers.*.font' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.size' => ['sometimes', 'nullable', 'numeric', 'min:8', 'max:160'],
            'layers.*.color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'layers.*.box_color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'layers.*.x' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.y' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.start' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.end' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.public_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.asset_public_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.asset_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'layers.*.asset_disk' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.asset_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.width' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:4096'],
            'layers.*.height' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:4096'],
            'layers.*.gravity' => ['sometimes', 'nullable', 'string', 'max:40'],
            'filters' => ['sometimes', 'array'],
            'trim' => ['sometimes', 'array'],
            'audio' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'overlays' => ['sometimes', 'array'],
            'drawing_files' => ['sometimes', 'array', 'max:30'],
            'drawing_files.*' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
