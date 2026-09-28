<?php

namespace App\Notifications;

use App\Models\LiveBattle;
use App\Models\LiveSession;

class LiveBattleInvitationNotification extends CreatorLiveStartedNotification
{
    public function __construct(LiveSession $live, public readonly LiveBattle $battle)
    {
        parent::__construct($live);
    }

    public function broadcastType(): string
    {
        return 'live.battle_invited';
    }

    public function payload(object $notifiable): array
    {
        return array_merge(parent::payload($notifiable), [
            'notification_id' => sprintf('live.battle_invited:%d:%d', $this->battle->id, $notifiable->id),
            'type' => $this->broadcastType(),
            'title' => 'Live battle invitation',
            'body' => ($this->live->creator->name ?: 'A creator').' invited you to join their live battle.',
            'battle_id' => $this->battle->id,
        ]);
    }

    public function toFcm(object $notifiable): array
    {
        $payload = $this->payload($notifiable);
        return ['title' => $payload['title'], 'body' => $payload['body'], 'data' => $payload];
    }
}
