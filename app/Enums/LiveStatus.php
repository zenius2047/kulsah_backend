<?php

namespace App\Enums;

enum LiveStatus: string
{
    case SCHEDULED = 'scheduled';
    case CREATED = 'created';
    case STARTING = 'starting';
    case LIVE = 'live';
    case RECONNECTING = 'reconnecting';
    case ENDING = 'ending';
    case ENDED = 'ended';
    case TERMINATED = 'terminated';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::SCHEDULED => [self::STARTING, self::CANCELLED],
            self::CREATED => [self::STARTING, self::CANCELLED],
            self::STARTING => [self::LIVE, self::FAILED, self::ENDING],
            self::LIVE => [self::RECONNECTING, self::ENDING, self::TERMINATED],
            self::RECONNECTING => [self::LIVE, self::ENDING, self::FAILED],
            self::ENDING => [self::ENDED, self::TERMINATED, self::FAILED],
            default => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ENDED, self::TERMINATED, self::CANCELLED, self::FAILED], true);
    }
}

