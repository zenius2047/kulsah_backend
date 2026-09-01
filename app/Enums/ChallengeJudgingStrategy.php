<?php

namespace App\Enums;

enum ChallengeJudgingStrategy: string
{
    case Points = 'points';
    case WeightedNormalized = 'weighted_normalized';
}
