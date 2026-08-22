<?php

namespace App\Domain\Challenges\Actions;

use App\Models\ChallengeAuditLog;
use App\Models\ChallengeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawChallengeEntry
{
    public function execute(ChallengeEntry $entry, User $user): ChallengeEntry
    {
        return DB::transaction(function () use ($entry, $user): ChallengeEntry {
            $entry = ChallengeEntry::lockForUpdate()->findOrFail($entry->id);
            if ((int) $entry->creator_id !== (int) $user->id || ! in_array($entry->status, ['active', 'submitted', 'pending_review'], true)) {
                throw ValidationException::withMessages(['entry' => 'This entry cannot be withdrawn.']);
            }

            $entry->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            ChallengeAuditLog::create(['challenge_id' => $entry->challenge_id, 'actor_user_id' => $user->id, 'action' => 'entry.withdrawn', 'subject_type' => ChallengeEntry::class, 'subject_id' => $entry->id]);

            return $entry->refresh();
        });
    }
}
