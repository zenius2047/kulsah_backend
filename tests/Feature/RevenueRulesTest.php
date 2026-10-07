<?php

namespace Tests\Feature;

use App\Events\AdminConsoleDataChanged;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RevenueRulesTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'testing');
        if (PHP_OS_FAMILY === 'Windows') {
            $app['config']->set('database.connections.testing.host', '127.0.0.1');
        }

        return $app;
    }

    private function admin(string $role = 'Finance Officer'): User
    {
        $uuid = (string) Str::uuid();
        $user = User::create([
            'name' => 'Finance '.$role,
            'username' => 'finance-'.substr($uuid, 0, 8),
            'email' => $uuid.'@example.test',
            'password' => Hash::make('FinanceTest123!'),
            'activated' => true,
            'admin_console_role' => $role,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        return $user;
    }

    public function test_rule_requires_another_finance_admin_and_preview_uses_the_pending_version(): void
    {
        Event::fake([AdminConsoleDataChanged::class]);
        $submitter = $this->admin();
        $reviewer = $this->admin();
        $this->withToken($reviewer->createToken('admin-console')->plainTextToken)
            ->getJson('/api/v1/admin/revenue-rules')
            ->assertOk()
            ->assertJsonPath('data.approverRoles.0', 'Super Admin')
            ->assertJsonPath('data.approverRoles.1', 'Finance Officer');
        $input = [
            'source' => 'creator_subscription', 'scope' => [], 'deductionType' => 'percentage', 'value' => 30,
            'currency' => 'GHS', 'payer' => 'creator', 'recipient' => 'platform', 'remainingRecipient' => 'creator',
            'minimum' => null, 'maximum' => null, 'effectiveAt' => now()->subMinute()->toIso8601String(),
            'status' => 'active', 'description' => 'Standard creator subscription share.',
        ];

        $this->withToken($submitter->createToken('admin-console')->plainTextToken)
            ->postJson('/api/v1/admin/revenue-rules', $input)
            ->assertCreated()
            ->assertJsonPath('data.approvalStatus', 'pending');
        $submitted = Illuminate\Support\Facades\DB::table('revenue_rule_versions')->first();

        $this->postJson('/api/v1/admin/revenue-rules/'.$submitted->revenue_rule_id.'/approve', ['versionId' => $submitted->id])
            ->assertForbidden();

        $this->withToken($reviewer->createToken('admin-console')->plainTextToken)
            ->postJson('/api/v1/admin/revenue-rules/'.$submitted->revenue_rule_id.'/approve', ['versionId' => $submitted->id])
            ->assertOk();

        $this->getJson('/api/v1/admin/revenue-rules/'.$submitted->revenue_rule_id.'/history')
            ->assertOk()
            ->assertJsonPath('data.versions.0.created_by_name', $submitter->name)
            ->assertJsonPath('data.versions.0.reviewed_by_name', $reviewer->name)
            ->assertJsonFragment(['actor_name' => $reviewer->name]);

        $input['ruleId'] = $submitted->revenue_rule_id;
        $input['value'] = 25;
        $input['scope'] = [];
        $this->postJson('/api/v1/admin/revenue-rules/preview', [
            'source' => 'creator_subscription', 'gross' => 100, 'currency' => 'GHS', 'context' => [],
            'draft' => [
                'ruleId' => $submitted->revenue_rule_id, 'source' => 'creator_subscription', 'deductionType' => 'percentage', 'value' => 25,
                'currency' => 'GHS', 'payer' => 'creator', 'recipient' => 'platform', 'remainingRecipient' => 'creator',
                'minimum' => null, 'maximum' => null, 'scope' => [],
            ],
        ])->assertOk()->assertJsonPath('data.recipientNet', 75)->assertJsonPath('data.deductions.0.amount', 25);
    }
}
