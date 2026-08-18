<?php

namespace App\Domain\Challenges\Actions;

use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeJuryMember;
use App\Models\User;

class InviteJuryMember
{
    public function execute(Challenge $challenge, User $judge, User $actor, string $role = 'judge', int $weightBps = 10000): ChallengeJuryMember
    {
        $member = ChallengeJuryMember::updateOrCreate(['challenge_id' => $challenge->id, 'user_id' => $judge->id], ['role' => $role, 'weight_bps' => $weightBps, 'status' => 'pending', 'invited_at' => now()]);
        ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $actor->id, 'action' => 'jury.invited', 'subject_type' => ChallengeJuryMember::class, 'subject_id' => $member->id, 'after' => ['user_id' => $judge->id, 'role' => $role]]);

        return $member;
    }
}
