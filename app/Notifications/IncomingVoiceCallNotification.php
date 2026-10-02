<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class IncomingVoiceCallNotification extends Notification
{
    public function __construct(public readonly VoiceCall $call, public readonly User $caller) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toDatabase(object $notifiable): array { return $this->payload(); }
    public function toBroadcast(object $notifiable): BroadcastMessage { return new BroadcastMessage($this->payload()); }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->caller->name ?: $this->caller->username,
            'body' => 'Incoming voice call',
            'data' => $this->payload(),
        ];
    }

    public function broadcastType(): string { return 'voice_call.incoming'; }

    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'notification_id' => 'voice_call.incoming:'.$this->call->id,
            'type' => 'voice_call.incoming',
            'call_id' => $this->call->id,
            'conversation_id' => $this->call->conversation_id,
            'caller' => [
                'id' => $this->caller->id,
                'name' => $this->caller->name,
                'username' => $this->caller->username,
                'avatar' => $this->caller->avatar,
            ],
            'created_at' => now()->toIso8601String(),
        ];
    }
}
