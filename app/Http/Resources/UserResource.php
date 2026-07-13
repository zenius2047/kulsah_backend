<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'handle' => $this->username,
            'avatar' => $this->avatar,
            'banner' => $this->banner,
            'bio' => $this->bio,
            'role' => $this->roles->first()?->name,
            'location' => $this->location,
            'total_followers' => $this->formatCount($this->total_followers),
            'total_subscribers' => $this->formatCount($this->total_subscribers),
            'total_likes' => $this->formatCount($this->total_likes),
            'wallet' => $this->whenLoaded('wallet', fn () => new WalletResource($this->wallet)),
            'verified' => $this->verified,
            'verified_at' => $this->verified_at,
            'activated' => $this->activated,
            'activated_at' => $this->activated_at,
            'vibes' => collect($this->onboarding?->vibe ?? [])
            ->map(fn ($vibe, $index) => [
                'id' => $index + 1,
                'name' => $vibe,
            ])
            ->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function formatCount(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return '0';
        }

        $value = (float) $value;

        if ($value >= 1000000000) {
            return $this->trimFormattedCount($value / 1000000000).'B';
        }

        if ($value >= 1000000) {
            return $this->trimFormattedCount($value / 1000000).'M';
        }

        if ($value >= 1000) {
            return $this->trimFormattedCount($value / 1000).'k';
        }

        return (string) (int) $value;
    }

    private function trimFormattedCount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
