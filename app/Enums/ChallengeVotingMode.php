<?php

namespace App\Enums;

enum ChallengeVotingMode: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case RankedChoice = 'ranked_choice';
    case PointsAllocation = 'points_allocation';
}
