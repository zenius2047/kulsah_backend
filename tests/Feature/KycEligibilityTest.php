<?php

namespace Tests\Feature;

use App\Models\KycApplication;
use App\Models\User;
use App\Services\AdminConsoleAccess;
use App\Services\KycEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KycEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_creator_badge_does_not_verify_identity_or_payout_ownership(): void
    {
        $user = User::factory()->create(['verified' => true]);

        $this->assertFalse(app(KycEligibilityService::class)->identityVerified($user));
        $this->assertFalse(app(KycEligibilityService::class)->payoutOwnershipVerified($user));
    }

    public function test_identity_eligibility_requires_all_provider_checks_and_current_document(): void
    {
        $user = User::factory()->create();
        $application = KycApplication::query()->create([
            'reference' => 'KYC-TEST-0001', 'user_id' => $user->id, 'applicant_type' => 'individual',
            'country_code' => 'GH', 'status' => 'verified', 'submitted_at' => now(), 'applicant_data' => [],
            'provider_status' => 'verified', 'document_authenticity_status' => 'passed',
            'face_match_status' => 'passed', 'liveness_status' => 'passed',
        ]);
        $service = app(KycEligibilityService::class);
        $this->assertTrue($service->identityVerified($user));

        $application->forceFill(['face_match_status' => 'pending'])->save();
        $this->assertFalse($service->identityVerified($user));

        $application->forceFill(['face_match_status' => 'passed', 'document_expires_at' => today()->subDay()])->save();
        $this->assertFalse($service->identityVerified($user));
    }

    public function test_manual_approval_satisfies_identity_rules_without_changing_payout_ownership(): void
    {
        $user = User::factory()->create(['verified' => false]);
        KycApplication::query()->create([
            'reference' => 'KYC-MANUAL-001', 'user_id' => $user->id, 'applicant_type' => 'individual',
            'country_code' => 'GH', 'status' => 'verified', 'submitted_at' => now(), 'applicant_data' => [],
            'review_method' => 'manual', 'reviewed_by' => $user->id, 'reviewed_at' => now(),
            'review_reason' => 'Approved after manual review.', 'payout_ownership_status' => 'pending',
        ]);

        $service = app(KycEligibilityService::class);
        $this->assertTrue($service->identityVerified($user));
        $this->assertFalse($service->payoutOwnershipVerified($user));
        $this->assertFalse($user->fresh()->verified, 'Manual ID approval must not award the public badge.');
    }

    public function test_roles_without_document_permission_cannot_open_identity_files(): void
    {
        $access = app(AdminConsoleAccess::class);
        $this->assertContains('kyc.view', $access->permissions('Operations Manager'));
        $this->assertContains('kyc.review', $access->permissions('Operations Manager'));
        $this->assertNotContains('kyc.documents.view', $access->permissions('Operations Manager'));
        $this->assertNotContains('kyc.config', $access->permissions('Operations Manager'));
    }
}
