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
            'handle' => $this->handle,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'role' => $this->roles->pluck('name'),
            'location' => $this->location,
            'verified' => $this->verified,
            'verified_at' => $this->verified_at,
            'activated' => $this->activated,
            'activated_at' => $this->activated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
