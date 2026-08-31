<?php

namespace App\Enums;

enum LiveCohostRequestStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case ACTIVE = 'active';
    case REMOVED = 'removed';
}
