<?php

namespace Tests\Feature;

use App\Models\AdminConsoleAudit;
use App\Models\KulCoinPackage;
use App\Models\KulCoinWallet;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminConsoleResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

$devRoot = getenv('KULSAH_TEST_DEV_ROOT');
if ($devRoot && ! class_exists('Mockery')) {
    require_once $devRoot.'/mockery/library/Mockery.php';
    require_once $devRoot.'/hamcrest/hamcrest/Hamcrest.php';
    spl_autoload_register(function ($class) use ($devRoot) {
        foreach (['Mockery\\' => '/mockery/library/Mockery/', 'Hamcrest\\' => '/hamcrest/hamcrest/Hamcrest/'] as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $path = $devRoot.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
        }
    });
}

require_once dirname(__DIR__).'/TestCase.php';

// This also permits validation of the prepared patch from /tmp without installing it.
$prepared = dirname(__DIR__, 2);
if (is_file($prepared.'/app/Services/AdminConsoleAccess.php')) {
    spl_autoload_register(function ($class) use ($prepared) {
        if (str_starts_with($class, 'App\\')) {
            $file = $prepared.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }, true, true);
}

class AdminConsoleTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasColumn('users', 'console_status')) {
            (require dirname(__DIR__, 2).'/database/migrations/2026_10_07_000001_create_admin_console_tables.php')->up();
        }
        if (! Route::has('admin.console.test.marker') && ! collect(Route::getRoutes())->contains(fn ($r) => $r->uri() === 'api/v1/admin/session')) {
            Route::middleware('api')->prefix('api/v1/admin')->withoutMiddleware([EnsureFrontendRequestsAreStateful::class])->group(dirname(__DIR__, 2).'/routes/admin-console.php');
        }
    }

    private function consoleUser(array $data = []): User
    {
        $key = (string) Str::uuid();

        return User::create([...['name' => 'Test User', 'username' => 'test-'.substr($key, 0, 8), 'email' => $key.'@example.test', 'password' => Hash::make('ConsoleTest123!'), 'activated' => true], ...$data]);
    }

    private function admin(string $role = 'Super Admin'): User
    {
        $u = $this->consoleUser(['password' => Hash::make('ConsoleTest123!')]);
        $u->forceFill(['admin_console_role' => $role])->save();
        $u->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        return $u;
    }

    private function staff(User $user): void
    {
        auth()->forgetGuards();
        $this->withToken($user->createToken('admin-console', ['admin-console'])->plainTextToken);
    }

    public function test_login_requires_real_password_and_existing_admin_role(): void
    {
        $u = $this->admin();
        $this->postJson('/api/v1/admin/login', ['email' => $u->email, 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/api/v1/admin/login', ['email' => $u->email, 'password' => 'ConsoleTest123!'])->assertOk()->assertJsonPath('data.role', 'Super Admin')->assertJsonPath('data.twoFactorEnabled', false)->assertJsonStructure(['token']);
        $fan = $this->consoleUser(['password' => Hash::make('ConsoleTest123!')]);
        $this->postJson('/api/v1/admin/login', ['email' => $fan->email, 'password' => 'ConsoleTest123!'])->assertForbidden();
    }

    public function test_guest_and_support_agent_cannot_change_platform_data(): void
    {
        $this->getJson('/api/v1/admin/resources/users')->assertUnauthorized();
        $this->staff($this->admin('Support Agent'));
        $target = $this->consoleUser();
        $this->getJson('/api/v1/admin/resources/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson('/api/v1/admin/actions', ['resource' => 'users', 'action' => 'suspend', 'ids' => [(string) $target->id]])->assertForbidden();
        $this->assertSame('Active', $target->fresh()->console_status);
    }

    public function test_admin_assignment_normalizes_email_and_invites_new_staff(): void
    {
        $actor = $this->admin();
        $account = $this->consoleUser(['email' => 'new.staff@example.test']);
        $this->staff($actor);

        $this->postJson('/api/v1/admin/resources/admins', [
            'name' => $account->name,
            'email' => '  NEW.STAFF@EXAMPLE.TEST  ',
            'role' => 'Finance Officer',
        ])->assertCreated()->assertJsonPath('data.invitationSent', false);
        $this->assertSame('Finance Officer', $account->fresh()->admin_console_role);
        $this->assertTrue($account->fresh()->roles()->where('name', 'admin')->exists());

        $this->postJson('/api/v1/admin/resources/admins', [
            'name' => 'Unregistered Staff',
            'email' => 'not.registered@example.test',
            'role' => 'Support Agent',
        ])->assertCreated()->assertJsonPath('data.invitationSent', true)->assertJsonPath('data.status', 'Invited');

        $invited = User::where('email', 'not.registered@example.test')->firstOrFail();
        $this->assertFalse($invited->activated);
        $this->assertTrue($invited->roles()->where('name', 'admin')->exists());
        $this->postJson('/api/v1/admin/login', ['email' => $invited->email, 'password' => 'ConsoleTest123!'])->assertUnprocessable();

        $token = Str::random(64);
        \DB::table('admin_invitations')->where('user_id', $invited->id)->update(['token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/v1/admin/invitations/accept', [
            'token' => $token,
            'password' => 'NewAdminPassword123!',
            'password_confirmation' => 'NewAdminPassword123!',
        ])->assertOk();
        $this->assertTrue($invited->fresh()->activated);
        $this->postJson('/api/v1/admin/login', ['email' => $invited->email, 'password' => 'NewAdminPassword123!'])->assertOk();
    }

    public function test_suspension_persists_revokes_tokens_and_creates_audit(): void
    {
        $admin = $this->admin();
        $target = $this->consoleUser();
        $target->createToken('mobile');
        $this->staff($admin);
        $this->postJson('/api/v1/admin/actions', ['resource' => 'users', 'action' => 'suspend', 'ids' => [(string) $target->id]])->assertOk();
        $this->assertSame('Suspended', $target->fresh()->console_status);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('admin_console_audits', ['admin_id' => $admin->id, 'entity' => 'users', 'entity_id' => (string) $target->id, 'action' => 'suspend']);
    }

    public function test_package_updates_are_persistent_and_only_accept_ghs(): void
    {
        $this->staff($this->admin());
        $payload = ['name' => 'Test package', 'coins' => 100, 'bonus' => 10, 'price' => 2.5, 'currency' => 'GHS', 'discount' => 0, 'order' => 1, 'status' => 'Active'];
        $created = $this->postJson('/api/v1/admin/resources/packages', $payload)->assertCreated()->assertJsonPath('data.price', 2.5);
        $id = $created->json('data.id');
        $this->postJson('/api/v1/admin/actions', ['resource' => 'packages', 'action' => 'archive', 'ids' => [$id]])->assertOk();
        $this->assertFalse(KulCoinPackage::find($id)->is_active);
        $this->postJson('/api/v1/admin/resources/packages', [...$payload, 'currency' => 'USD'])->assertUnprocessable();
    }

    public function test_coin_adjustments_require_second_approver_and_are_applied_once(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $user = $this->consoleUser();
        $this->staff($requester);
        $response = $this->postJson('/api/v1/admin/resources/adjustments', ['userId' => $user->id, 'coins' => 100, 'kind' => 'Credit', 'reason' => 'Test compensation reason'])->assertCreated();
        $ids = [$response->json('data.id')];
        $this->postJson('/api/v1/admin/actions', ['resource' => 'adjustments', 'action' => 'approve', 'ids' => $ids])->assertUnprocessable();
        $this->staff($approver);
        $this->postJson('/api/v1/admin/actions', ['resource' => 'adjustments', 'action' => 'approve', 'ids' => $ids])->assertOk();
        $this->assertSame(100, KulCoinWallet::where('user_id', $user->id)->first()->available_balance_kc);
        $this->postJson('/api/v1/admin/actions', ['resource' => 'adjustments', 'action' => 'approve', 'ids' => $ids])->assertUnprocessable();
        $this->assertSame(100, KulCoinWallet::where('user_id', $user->id)->first()->available_balance_kc);
        $this->assertDatabaseCount('kulcoin_ledger_entries', 2);
    }

    public function test_all_resource_endpoints_return_valid_real_data_shapes(): void
    {
        $this->staff($this->admin());
        foreach (array_keys(AdminConsoleResources::READ_PERMISSIONS) as $resource) {
            $this->getJson('/api/v1/admin/resources/'.$resource)->assertOk()->assertJsonStructure(['data', 'meta' => ['total', 'last_page']]);
        }
        $this->getJson('/api/v1/admin/dashboard?range=7d')->assertOk()->assertJsonPath('data.kpis.0.value', '1');
        $this->getJson('/api/v1/admin/coin-overview?days=30')->assertOk()->assertJsonPath('data.purchased', 0);
        $this->getJson('/api/v1/admin/revenue')->assertOk()->assertJsonPath('data.gross', 0);
        $this->getJson('/api/v1/admin/coin-config')->assertOk()->assertJsonStructure(['data' => ['coinValueGhs', 'creatorShare']]);
    }

    public function test_permission_changes_apply_to_existing_sessions(): void
    {
        $super = $this->admin();
        $support = $this->admin('Support Agent');
        $this->staff($super);
        $this->postJson('/api/v1/admin/resources/roles', ['name' => 'Support Agent', 'permissions' => ['analytics.view']])->assertCreated();
        $this->staff($support);
        $this->getJson('/api/v1/admin/resources/users')->assertForbidden();
        $this->getJson('/api/v1/admin/session')->assertOk()->assertJsonPath('data.permissions', ['analytics.view']);
    }

    public function test_profile_password_and_preferences_persist_without_secret_audits(): void
    {
        $u = $this->admin();
        $this->staff($u);
        $this->patchJson('/api/v1/admin/profile', ['name' => 'Updated Admin', 'bio' => 'Staff bio'])->assertOk()->assertJsonPath('data.name', 'Updated Admin');
        $this->patchJson('/api/v1/admin/preferences', ['timezone' => 'Africa/Accra', 'language' => 'en', 'density' => 'comfortable'])->assertOk();
        $this->postJson('/api/v1/admin/password', ['current_password' => 'ConsoleTest123!', 'password' => 'NewConsole123!', 'password_confirmation' => 'NewConsole123!'])->assertOk();
        $this->assertTrue(Hash::check('NewConsole123!', $u->fresh()->password));
        $this->assertStringNotContainsString('NewConsole123!', AdminConsoleAudit::all()->toJson());
    }
}
