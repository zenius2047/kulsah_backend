<?php

namespace App\Enums;

enum ChallengeVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case InviteOnly = 'invite_only';
}
