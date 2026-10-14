<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AnchorKulsanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnchorKulsanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AnchorKulsanService $anchors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->anchors = app(AnchorKulsanService::class);
    }

    public function test_configuration_requires_at_least_one_supported_enabled_milestone(): void
    {
        try {
            $this->anchors->saveConfiguration($this->admin, [
                'qualificationRule' => 'ALL', 'countExistingActivity' => true,
                'milestones' => [['type' => 'followers', 'enabled' => false, 'target' => 0]],
            ]);
            $this->fail('An empty milestone configuration must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('anchor_kulsan_configurations', 0);
        }
    }

    public function test_candidate_is_recognized_once_the_pinned_milestone_is_met(): void
    {
        $creator = $this->creator();
        $follower = User::factory()->create();
        $this->configureFollowers(1);

        $candidate = $this->anchors->addCandidate($this->admin, (int) $creator->id);
        $this->assertSame('In Progress', $candidate['status']);
        $this->assertNotEmpty($candidate['referralCode']);

        DB::table('user_follows')->insert(['follower_id' => $follower->id, 'followed_id' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->recordReferral((int) $creator->id);
        $this->assertTrue($this->anchors->evaluateMembership((int) DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('id')));

        $member = DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->first();
        $this->assertSame('recognized', $member->status);
        $this->assertMatchesRegularExpression('/^AK-[0-9]{3,}$/', $member->anchor_id);
        $this->assertSame('active', $member->badge_status);
    }

    public function test_revoked_badge_is_not_automatically_restored_and_keeps_its_id(): void
    {
        $creator = $this->creator();
        $follower = User::factory()->create();
        DB::table('user_follows')->insert(['follower_id' => $follower->id, 'followed_id' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->configureFollowers(1);
        $member = $this->anchors->addCandidate($this->admin, (int) $creator->id);
        $this->assertSame('In Progress', $member['status']);
        $this->recordReferral((int) $creator->id);
        $this->anchors->evaluate();
        $this->assertSame('recognized', DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('status'));

        $originalId = DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('anchor_id');
        $this->anchors->act($this->admin, (int) $creator->id, 'revoke', 'Policy breach');
        $this->anchors->evaluate();

        $stored = DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->first();
        $this->assertSame('revoked', $stored->badge_status);
        $this->assertSame($originalId, $stored->anchor_id);
        $this->anchors->act($this->admin, (int) $creator->id, 'restore', 'Appeal accepted');
        $this->assertSame('active', DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('badge_status'));
        $this->assertSame($originalId, DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('anchor_id'));
    }

    public function test_duplicate_candidate_selection_is_rejected_and_deactivation_is_reversible(): void
    {
        $creator = $this->creator();
        $this->configureFollowers(100);
        $this->anchors->addCandidate($this->admin, (int) $creator->id);
        try {
            $this->anchors->addCandidate($this->admin, (int) $creator->id);
            $this->fail('Duplicate candidate selection must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->anchors->act($this->admin, (int) $creator->id, 'remove', 'Program fit changed');
            $this->assertSame('deactivated', DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('status'));
            $this->anchors->act($this->admin, (int) $creator->id, 'activate', 'Creator resumed participation');
            $this->assertSame('in_progress', DB::table('anchor_kulsan_memberships')->where('user_id', $creator->id)->value('status'));
            $this->assertDatabaseCount('anchor_kulsan_memberships', 1);
        }
    }

    private function creator(): User
    {
        $creator = User::factory()->create(['console_status' => 'Active']);
        $role = Role::firstOrCreate(['name' => 'creator']);
        $creator->roles()->attach($role->id);
        return $creator;
    }

    private function configureFollowers(int $target): void
    {
        $this->anchors->saveConfiguration($this->admin, [
            'qualificationRule' => 'ALL', 'countExistingActivity' => true,
            'milestones' => [
                ['type' => 'followers', 'enabled' => true, 'target' => $target],
                ['type' => 'referrals', 'enabled' => true, 'target' => 1],
            ],
        ]);
    }

    private function recordReferral(int $creatorId): void
    {
        $membershipId = DB::table('anchor_kulsan_memberships')->where('user_id', $creatorId)->value('id');
        $referredUser = User::factory()->create(['activated' => true]);
        DB::table('anchor_referral_attributions')->insert([
            'anchor_membership_id' => $membershipId,
            'referred_user_id' => $referredUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
