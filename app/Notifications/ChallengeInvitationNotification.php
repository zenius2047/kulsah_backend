<?php

namespace App\Notifications;

use App\Models\Challenge;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ChallengeInvitationNotification extends Notification
{
    public function __construct(
        public readonly Challenge $challenge,
        public readonly User $actor,
        public readonly string $invitationType = 'challenge_participant',
        public readonly ?int $inviteId = null,
        public readonly string $role = 'challenger',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function toFcm(object $notifiable): array
    {
        $title = $this->invitationType === 'jury_invite'
            ? 'You were invited to join the jury'
            : 'You were invited to a creator battle';

        return [
            'title' => $title,
            'body' => sprintf(
                '%s invited you to %s.',
                $this->actor->name ?: $this->actor->username,
                $this->challenge->title
            ),
            'data' => $this->payload($notifiable),
        ];
    }

    public function broadcastType(): string
    {
        return 'challenge.invited';
    }

    public function payload(object $notifiable): array
    {
        return [
            'type' => 'challenge.invited',
            'invitation_type' => $this->invitationType,
            'challenge_id' => $this->challenge->id,
            'challenge_title' => $this->challenge->title,
            'challenge_slug' => $this->challenge->slug,
            'challenge_mode' => $this->challenge->mode,
            'challenge_status' => $this->challenge->status,
            'invite_id' => $this->inviteId,
            'role' => $this->role,
            'invited_by' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'username' => $this->actor->username,
            ],
            'notified_user_id' => $notifiable->id ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
