<?php

namespace App\Domain\Challenges\Scoring;

use App\Models\Challenge;
use App\Models\ChallengeEntry;
use App\Models\ChallengeScoringComponent;

interface ChallengeScoreComponent
{
    public function calculate(Challenge $challenge, ChallengeEntry $entry, ChallengeScoringComponent $component): ScoreResult;
}
