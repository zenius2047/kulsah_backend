<?php

namespace App\Domain\Challenges\Services;

use App\Models\ChallengeRule;

class ChallengeRuleEngine
{
    public function passes(ChallengeRule $rule, mixed $actual): bool
    {
        $required = $rule->value;

        return match (strtoupper($rule->operator)) {
            '=' => $actual == $required,
            '!=' => $actual != $required,
            '>' => $actual > $required,
            '>=' => $actual >= $required,
            '<' => $actual < $required,
            '<=' => $actual <= $required,
            'IN' => in_array($actual, (array) $required, true),
            'NOT_IN' => ! in_array($actual, (array) $required, true),
            'BETWEEN' => count((array) $required) === 2 && $actual >= $required[0] && $actual <= $required[1],
            default => false,
        };
    }
}
