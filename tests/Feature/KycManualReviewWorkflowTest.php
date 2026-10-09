<?php

namespace Tests\Feature;

use App\Models\AdminConsoleRecord;
use App\Models\KycApplication;
use App\Models\Role;
use App\Models\User;
use App\Services\CountrySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycManualReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        AdminConsoleRecord::query()->create([
            'resource' => 'country-settings',
            'payload' => [
                'globalDefaults' => ['verification' => ['kyc' => [
                    'acceptedDocuments' => ['individual' => ['passport']],
                    'requiredCaptures' => ['individual' => ['front']],
                    'selfieRequired' => true,
                ]]],
                'countryOverrides' => [],
            ],
        ]);
        app(CountrySettingsService::class)->clearCache();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['country_code' => 'GH'])->save();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function token(User $user): void
    {
        auth()->forgetGuards();
        $this->withToken($user->createToken('kyc-test', $user->roles()->where('name', 'admin')->exists() ? ['admin-console'] : [])->plainTextToken);
    }

    private function submit(User $creator): array
    {
        $this->token($creator);
        $response = $this->post('/api/v1/creator/kyc/applications', [
            'legal_name' => 'Example Creator',
            'date_of_birth' => '1992-04-05',
            'document_type' => 'passport',
            'documents' => [
                'front' => UploadedFile::fake()->image('front.jpg'),
                'selfie' => UploadedFile::fake()->image('photo.jpg'),
            ],
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'pending');

        return $response->json('data');
    }

    private function administrator(): User
    {
        $admin = $this->userWithRole('admin');
        $admin->forceFill(['admin_console_role' => 'Super Admin', 'console_status' => 'Active', 'admin_console_disabled' => false])->save();

        return $admin;
    }

    public function test_submission_and_manual_approval_work_without_an_automated_provider(): void
    {
        $creator = $this->userWithRole('creator');
        $submitted = $this->submit($creator);
        $application = KycApplication::query()->where('reference', $submitted['reference'])->firstOrFail();
        $admin = $this->administrator();
        $this->token($admin);

        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'begin_review', 'expectedVersion' => 0])->assertOk();
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'approve', 'expectedVersion' => 1])
            ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.reviewMethod', 'manual');

        $application->refresh();
        $this->assertSame('manual', $application->review_method);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertSame('Approved after manual review.', $application->review_reason);
        $this->assertFalse((bool) $creator->fresh()->verified);
    }

    public function test_rejection_resubmission_restarts_pending_review_without_inheriting_approval(): void
    {
        $creator = $this->userWithRole('creator');
        $submitted = $this->submit($creator);
        $application = KycApplication::query()->where('reference', $submitted['reference'])->firstOrFail();
        $admin = $this->administrator();
        $this->token($admin);
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'begin_review', 'expectedVersion' => 0])->assertOk();
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", [
            'action' => 'request_resubmission', 'expectedVersion' => 1,
            'message' => 'The front image is blurry. Upload a clearer image.',
        ])->assertOk()->assertJsonPath('data.status', 'resubmission_required');

        $this->token($creator);
        $this->post('/api/v1/creator/kyc/applications/'.$application->id.'/resubmissions', [
            'documents' => [
                'front' => UploadedFile::fake()->image('clear-front.jpg'),
                'selfie' => UploadedFile::fake()->image('new-photo.jpg'),
            ],
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $application->refresh();
        $this->assertSame('pending', $application->status);
        $this->assertNull($application->review_method);
        $this->assertNull($application->reviewed_by);
        $this->assertNull($application->reviewed_at);
        $this->assertSame(2, $application->documents()->max('submission_version'));
    }

    public function test_review_decisions_require_document_access_and_stale_versions_are_rejected(): void
    {
        $creator = $this->userWithRole('creator');
        $submitted = $this->submit($creator);
        $application = KycApplication::query()->where('reference', $submitted['reference'])->firstOrFail();
        $restricted = $this->userWithRole('admin');
        $restricted->forceFill(['admin_console_role' => 'Operations Manager', 'console_status' => 'Active', 'admin_console_disabled' => false])->save();
        $this->token($restricted);
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'approve', 'expectedVersion' => 0])->assertForbidden();

        $admin = $this->administrator();
        $this->token($admin);
        $document = $application->documents()->firstOrFail();
        $this->get("/api/v1/admin/kyc/applications/{$application->id}/documents/{$document->id}")->assertOk();
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'begin_review', 'expectedVersion' => 0])->assertOk();
        $this->postJson("/api/v1/admin/kyc/applications/{$application->id}/actions", ['action' => 'approve', 'expectedVersion' => 0])->assertUnprocessable();
    }
}
