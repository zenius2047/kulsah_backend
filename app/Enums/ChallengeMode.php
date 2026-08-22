<?php

namespace App\Enums;

enum ChallengeMode: string
{
    case Open = 'open';
    case InviteOnly = 'invite_only';
    case CreatorBattle = 'creator_battle';
}
