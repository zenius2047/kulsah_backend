<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'cover_url' => $this->cover_url,
            'visibility' => $this->visibility,
            'status' => $this->status?->value ?? $this->status,
            'provider' => $this->provider,
            'provider_channel' => $this->provider_channel,
            'join_endpoint' => url('/api/v1/general/live/'.$this->public_id.'/join'),
            'preview_endpoint' => url('/api/v1/general/live/'.$this->public_id.'/preview'),
            'scheduled_at' => optional($this->scheduled_at)?->toIso8601String(),
            'started_at' => optional($this->started_at)?->toIso8601String(),
            'ended_at' => optional($this->ended_at)?->toIso8601String(),
            'chat_enabled' => (bool) $this->chat_enabled,
            'gifts_enabled' => (bool) $this->gifts_enabled,
            'recording_enabled' => (bool) $this->recording_enabled,
            'notify_followers' => (bool) $this->notify_followers,
            'age_restricted' => (bool) $this->age_restricted,
            'stream_quality' => $this->stream_quality,
            'orientation' => $this->orientation,
            'moderation' => $this->moderation ?? [
                'profanity_filter_enabled' => false,
                'followers_only_chat' => false,
                'slow_mode_seconds' => null,
                'blocked_words' => [],
            ],
            'current_viewers' => (int) $this->current_viewers,
            'unique_viewers' => (int) $this->unique_viewers,
            'peak_viewers' => (int) $this->peak_viewers,
            'average_viewers' => (int) $this->average_viewers,
            'watch_seconds_total' => (int) $this->watch_seconds_total,
            'likes_count' => (int) $this->likes_count,
            'comments_count' => (int) $this->comments_count,
            'gifts_count' => (int) $this->gifts_count,
            'gift_value_kc' => (int) $this->gift_value_kc,
            'earnings_kc' => (int) $this->earnings_kc,
            'termination_reason' => $this->termination_reason,
            'recording_state' => $this->recording_state,
            'replay_state' => $this->replay_state,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}




