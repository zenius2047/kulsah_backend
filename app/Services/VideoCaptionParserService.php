<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class VideoCaptionParserService
{
    public function parse(string|null $caption): array
    {
        $caption = (string) $caption;

        $mentions = $this->extractMentions($caption);
        $hashtags = $this->extractHashtags($caption);

        return [
            'mentions' => $mentions,
            'hashtags' => $hashtags,
        ];
    }

    public function extractMentions(string $caption): array
    {
        preg_match_all('/(?<!\w)@([A-Za-z0-9_.]+)/u', $caption, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $handle) => $this->normalizeHandle($handle))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function extractHashtags(string $caption): array
    {
        preg_match_all('/(?<!\w)#([\p{L}\p{N}_]+)/u', $caption, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $tag) => mb_strtolower(trim($tag)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function resolveMentionedUsers(array $mentions): Collection
    {
        $normalized = collect($mentions)
            ->map(fn (string $handle) => $this->normalizeHandle($handle))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return new Collection();
        }

        $lookupValues = $normalized
            ->flatMap(fn (string $handle) => [$handle, '@'.$handle])
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->whereIn('username', $lookupValues)
            ->get()
            ->unique('id')
            ->values();
    }

    public function normalizeHandle(string $handle): string
    {
        return ltrim(trim($handle), '@');
    }
}
