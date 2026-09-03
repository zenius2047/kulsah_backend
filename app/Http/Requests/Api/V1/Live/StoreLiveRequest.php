<?php

namespace App\Http\Requests\Api\V1\Live;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $moderation = is_array($this->input('moderation')) ? $this->input('moderation') : [];
        $blockedWords = array_values(array_unique(array_filter(array_map(
            static fn ($word) => is_string($word) ? trim($word) : '',
            $moderation['blocked_words'] ?? []
        ))));

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
            'category' => is_string($this->input('category')) ? strtolower(trim($this->input('category'))) : $this->input('category'),
            'visibility' => is_string($this->input('visibility')) ? strtolower(trim($this->input('visibility'))) : $this->input('visibility'),
            'stream_quality' => is_string($this->input('stream_quality'))
                ? strtolower(trim($this->input('stream_quality')))
                : '1080p_30fps',
            'orientation' => is_string($this->input('orientation'))
                ? strtolower(trim($this->input('orientation')))
                : 'portrait',
            'notify_followers' => $this->has('notify_followers') ? (bool) $this->boolean('notify_followers') : true,
            'recording_enabled' => $this->has('recording_enabled') ? (bool) $this->boolean('recording_enabled') : false,
            'chat_enabled' => $this->has('chat_enabled') ? (bool) $this->boolean('chat_enabled') : true,
            'gifts_enabled' => $this->has('gifts_enabled') ? (bool) $this->boolean('gifts_enabled') : true,
            'age_restricted' => $this->has('age_restricted') ? (bool) $this->boolean('age_restricted') : false,
            'moderation' => array_merge([
                'profanity_filter_enabled' => false,
                'followers_only_chat' => false,
                'slow_mode_seconds' => null,
                'blocked_words' => [],
            ], $moderation, [
                'blocked_words' => $blockedWords,
            ]),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(['music', 'gaming', 'talk_show', 'lifestyle', 'education'])],
            'visibility' => ['required', Rule::in(['public', 'subscribers'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'notify_followers' => ['required', 'boolean'],
            'recording_enabled' => ['required', 'boolean'],
            'chat_enabled' => ['required', 'boolean'],
            'gifts_enabled' => ['required', 'boolean'],
            'age_restricted' => ['required', 'boolean'],
            'stream_quality' => ['required', Rule::in(['720p_30fps', '1080p_30fps', '1080p_60fps'])],
            'orientation' => ['required', Rule::in(['portrait', 'landscape', 'auto_rotate'])],
            'cover_url' => ['nullable', 'url', 'max:2048'],
            'moderation' => ['required', 'array'],
            'moderation.profanity_filter_enabled' => ['required', 'boolean'],
            'moderation.followers_only_chat' => ['required', 'boolean'],
            'moderation.slow_mode_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'moderation.blocked_words' => ['present', 'array'],
            'moderation.blocked_words.*' => ['string', 'max:100'],
        ];
    }
}
