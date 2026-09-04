<?php

namespace App\Http\Requests\Api\V1\Music;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MusicQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->filled('search')) {
            $payload['search'] = $this->normalizeSearch($this->input('search'));
        }

        if ($this->has('genre')) {
            $payload['genre'] = $this->normalizeList($this->input('genre'));
        }

        if ($this->has('mood')) {
            $payload['mood'] = $this->normalizeList($this->input('mood'));
        }

        if ($this->has('time')) {
            $payload['time'] = is_string($this->input('time')) ? strtolower(trim($this->input('time'))) : $this->input('time');
        }

        if ($this->has('sort')) {
            $payload['sort'] = is_string($this->input('sort')) ? strtolower(trim($this->input('sort'))) : $this->input('sort');
        }

        if ($this->has('trending')) {
            $payload['trending'] = $this->boolean('trending');
        }

        if ($this->has('limit')) {
            $payload['limit'] = (int) $this->input('limit');
        }

        if ($this->has('page')) {
            $payload['page'] = (int) $this->input('page');
        }

        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'genre' => ['sometimes', 'array'],
            'genre.*' => ['string', 'max:80'],
            'mood' => ['sometimes', 'array'],
            'mood.*' => ['string', 'max:80'],
            'trending' => ['sometimes', 'nullable', 'boolean'],
            'time' => ['sometimes', 'nullable', Rule::in(['week', 'month', 'year', 'all_time', 'allTime'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('music.max_limit', 50)],
            'sort' => ['sometimes', 'nullable', Rule::in(['relevant', 'popular', 'recent'])],
        ];
    }

    private function normalizeSearch(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : '',
            $value
        ))));
    }
}
