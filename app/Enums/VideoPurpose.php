<?php

namespace App\Enums;

enum VideoPurpose: string
{
    case PostVideo = 'post_video';
    case ChallengeVideo = 'challenge_video';
    case ChallengeInstructionVideo = 'challenge_instruction_video';
    case ChallengeEntry = 'challenge_entry';
    case MessageVideo = 'message_video';
    case Other = 'other';
}
