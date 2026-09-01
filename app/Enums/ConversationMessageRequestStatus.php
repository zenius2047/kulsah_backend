<?php

namespace App\Enums;

enum ConversationMessageRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';
}
