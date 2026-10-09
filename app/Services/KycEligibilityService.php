<?php

namespace App\Services;

use App\Models\KycApplication;
use App\Models\User;

class KycEligibilityService
{
    public function currentApplication(User $user): ?KycApplication
    {
        return KycApplication::query()->where('user_id', $user->id)->latest('submitted_at')->first();
    }

    public function identityVerified(User $user): bool
    {
        $application = $this->currentApplication($user);
        if (!$application || $application->status !== 'verified') return false;
        if ($application->document_expires_at && $application->document_expires_at->isPast()) return false;
        if ($application->review_method === 'manual') {
            return $application->reviewed_by !== null && $application->reviewed_at !== null;
        }

        // Keep the result of any legacy automated review intact. New manual approvals
        // are recorded explicitly above and never synthesize these historical checks.
        return $application->provider_status === 'verified'
            && $application->document_authenticity_status === 'passed'
            && $application->face_match_status === 'passed'
            && $application->liveness_status === 'passed';
    }

    public function payoutOwnershipVerified(User $user): bool
    {
        return $this->currentApplication($user)?->payout_ownership_status === 'passed';
    }
}
