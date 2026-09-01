<?php

namespace App\Domain\Challenges\Actions;

use App\Models\ChallengeAuditLog;
use App\Models\ChallengeIntegrityFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResolveIntegrityFlag
{
    public function execute(ChallengeIntegrityFlag $flag, User $actor, string $status, string $resolution): ChallengeIntegrityFlag
    {
        return DB::transaction(function () use ($flag, $actor, $status, $resolution): ChallengeIntegrityFlag {
            $flag = ChallengeIntegrityFlag::lockForUpdate()->findOrFail($flag->id);
            $before = $flag->toArray();
            $flag->update(['status' => $status, 'resolution' => $resolution, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);
            ChallengeAuditLog::create(['challenge_id' => $flag->challenge_id, 'actor_user_id' => $actor->id, 'action' => 'integrity_flag.resolved', 'subject_type' => ChallengeIntegrityFlag::class, 'subject_id' => $flag->id, 'before' => $before, 'after' => $flag->toArray()]);

            return $flag->refresh();
        });
    }
}
