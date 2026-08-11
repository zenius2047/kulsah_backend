<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscoveryCreatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $creator */
        $creator = $this->resource;

        return [
            'id' => (int) $creator->id,
            'name' => $creator->name ?: $creator->username,
            'handle' => ltrim((string) $creator->username, '@'),
            'avatar_url' => $creator->avatar,
            'is_verified' => (bool) $creator->verified,
            'is_live' => false,
            'is_following' => (bool) ($creator->viewer_is_following ?? false),
            'is_premium' => (bool) ($creator->has_active_subscription_plan ?? false),
            'followers_count' => (int) ($creator->followers_count ?? 0),
            'discovery_count' => (int) ($creator->discovery_count ?? 0),
            'style' => null,
            'tools' => [],
        ];
    }
}
