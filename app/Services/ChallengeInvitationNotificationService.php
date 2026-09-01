<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\User;
use App\Notifications\ChallengeInvitationNotification;
use App\Services\NotificationDeliveryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class ChallengeInvitationNotificationService
{
    /**
     * @param  iterable<User>|User  $notifiables
     */
    public function send(
        iterable|User $notifiables,
        Challenge $challenge,
        User $actor,
        string $invitationType,
        ?int $inviteId = null,
        string $role = 'challenger',
    ): void {
        $recipients = $notifiables instanceof User
            ? collect([$notifiables])
            : Collection::make($notifiables)->filter();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::sendNow(
            $recipients,
            new ChallengeInvitationNotification(
                challenge: $challenge,
                actor: $actor,
                invitationType: $invitationType,
                inviteId: $inviteId,
                role: $role,
            )
        );
    }
}

