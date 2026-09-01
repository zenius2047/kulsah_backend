<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'challenge_id' => $this->challenge_id, 'creator_id' => $this->creator_id, 'video_id' => $this->video_id, 'submission_number' => $this->submission_number, 'caption' => $this->caption, 'status' => $this->status, 'current_score' => $this->current_score, 'current_rank' => $this->current_rank, 'submitted_at' => optional($this->submitted_at)?->toIso8601String(), 'creator' => UserResource::make($this->whenLoaded('creator')), 'video' => VideoResource::make($this->whenLoaded('video'))];
    }
}
