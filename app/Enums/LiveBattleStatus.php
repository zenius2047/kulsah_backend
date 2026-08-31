<?php

namespace App\Enums;

enum LiveBattleStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case ACTIVE = 'active';
    case ENDED = 'ended';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case FAILED = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::PENDING => [self::ACCEPTED, self::CANCELLED, self::EXPIRED, self::FAILED],
            self::ACCEPTED => [self::ACTIVE, self::CANCELLED, self::EXPIRED, self::FAILED],
            self::ACTIVE => [self::ENDED, self::FAILED],
            default => [],
        }, true);
    }
}
