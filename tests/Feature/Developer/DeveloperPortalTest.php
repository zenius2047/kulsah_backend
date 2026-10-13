<?php

namespace Tests\Feature\Developer;

use App\Jobs\DeliverDeveloperWebhook;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\DeveloperTotp;
use App\Services\DeveloperWebhookUrl;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class DeveloperPortalTest extends TestCase
{
    use RefreshDatabase;

    private function developer(): User
    {
        return User::factory()->create(['activated' => true, 'console_status' => 'Active']);
    }

    private function app(User $u, string $name = 'Test App'): OAuthClient
    {
        return OAuthClient::forceCreate(['owner_type' => User::class, 'owner_id' => $u->id, 'name' => $name, 'secret' => 'secret', 'redirect_uris' => ['https://example.com/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'client_type' => 'web', 'revoked' => false]);
    }

    public function test_uploaded_logo_is_stored_and_persisted_on_application(): void
    {
        Storage::fake('public');
        $u=$this->developer();$this->actingAs($u,'sanctum');
        $upload=$this->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->image('logo.png',64,64)],['Accept'=>'application/json'])->assertCreated();
        Storage::disk('public')->assertExists($upload->json('data.path'));
        $this->assertStringContainsString('developer-logos/'.$u->id.'/', $upload->json('data.path'));
        $this->postJson('/api/v1/developer/apps',['name'=>'Uploaded logo app','logo'=>$upload->json('data.url'),'redirect_uris'=>['https://example.com/callback']])->assertCreated()->assertJsonPath('data.logo',$upload->json('data.url'));
    }

    public function test_logo_upload_rejects_unsupported_and_oversized_files(): void
    {
        Storage::fake('public');$this->actingAs($this->developer(),'sanctum');
        $this->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->create('logo.svg',1,'image/svg+xml')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->image('large.png')->size(2049)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->image('wide.png',4097,1)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertSame([],Storage::disk('public')->allFiles());
    }

    public function test_guests_and_workspace_viewers_cannot_upload_logos(): void
    {
        Storage::fake('public');
        $this->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->image('logo.png')],['Accept'=>'application/json'])->assertUnauthorized();
        $owner=$this->developer();$viewer=$this->developer();
        DB::table('developer_memberships')->insert(['owner_id'=>$owner->id,'user_id'=>$viewer->id,'role'=>'viewer','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($viewer,'sanctum')->withHeader('X-Workspace-Id',$owner->id)->post('/api/v1/developer/apps/logo',['logo'=>UploadedFile::fake()->image('logo.png')],['Accept'=>'application/json'])->assertForbidden();
        $this->assertSame([],Storage::disk('public')->allFiles());
    }

    public function test_guests_and_unactivated_users_cannot_access_portal(): void
    {
        $this->getJson('/api/v1/developer/dashboard')->assertUnauthorized();
        $u = User::factory()->create(['activated' => false]);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/developer/dashboard')->assertForbidden();
    }

    public function test_dashboard_and_logs_are_scoped_and_not_sample_data(): void
    {
        $u = $this->developer();
        $other = $this->developer();
        $this->app($u);
        $this->app($other, 'Other');
        DB::table('developer_request_logs')->insert(['owner_id' => $u->id, 'actor_id' => $u->id, 'method' => 'POST', 'path' => '/oauth/token', 'status' => 401, 'duration_ms' => 42, 'created_at' => now()]);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/developer/dashboard')->assertOk()->assertJsonPath('data.applications', 1)->assertJsonPath('data.active_tokens', 0)->assertJsonPath('data.failed_requests_today', 1)->assertJsonCount(7, 'data.activity');
        $this->getJson('/api/v1/developer/logs?search=oauth')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 401);
        $this->withHeader('X-Workspace-Id', $other->id)->getJson('/api/v1/developer/logs')->assertForbidden();
    }

    public function test_shared_viewers_can_read_but_cannot_write_or_escalate_roles(): void
    {
        $owner = $this->developer();
        $viewer = $this->developer();
        $app = $this->app($owner);
        DB::table('developer_memberships')->insert(['owner_id' => $owner->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->withHeader('X-Workspace-Id', $owner->id)->getJson('/api/v1/developer/apps')->assertOk()->assertJsonPath('data.0.id', $app->id);
        $this->deleteJson('/api/v1/developer/apps/'.$app->id)->assertForbidden();
        $this->postJson('/api/v1/developer/team/invitations', ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
    }

    public function test_application_update_preserves_secrets_and_checks_owner(): void
    {
        $owner = $this->developer();
        $other = $this->developer();
        $app = $this->app($owner);
        $secret = $app->getRawOriginal('secret');
        $this->actingAs($other, 'sanctum')->patchJson('/api/v1/developer/apps/'.$app->id, ['name' => 'Changed', 'redirect_uris' => ['https://changed.example/callback']])->assertNotFound();
        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/developer/apps/'.$app->id, ['name' => 'Changed', 'description' => 'Saved', 'redirect_uris' => ['https://changed.example/callback']])->assertOk()->assertJsonPath('data.name', 'Changed')->assertJsonMissingPath('data.secret');
        $this->assertSame($secret, $app->fresh()->getRawOriginal('secret'));
    }

    public function test_oauth_revocation_is_owner_scoped_and_revokes_refresh_tokens(): void
    {
        $owner = $this->developer();
        $other = $this->developer();
        $app = $this->app($owner);
        DB::table('oauth_access_tokens')->insert(['id' => 'access-test', 'user_id' => $other->id, 'client_id' => $app->id, 'scopes' => '[]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'refresh-test', 'access_token_id' => 'access-test', 'revoked' => false, 'expires_at' => now()->addDay()]);
        $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/developer/tokens/access-test')->assertNotFound();
        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/developer/tokens/access-test')->assertOk();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'access-test', 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-test', 'revoked' => true]);
    }

    public function test_preferences_and_profile_persist(): void
    {
        $u = $this->developer();
        $this->actingAs($u, 'sanctum')->patchJson('/api/v1/developer/preferences', ['security_alerts' => false])->assertOk()->assertJsonPath('data.security_alerts', false);
        $this->patchJson('/api/v1/developer/profile', ['name' => 'New Name', 'username' => 'new_developer', 'location' => 'Kigali'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $u->id, 'name' => 'New Name', 'username' => 'new_developer', 'location' => 'Kigali']);
    }

    public function test_invitations_are_email_bound_single_use_and_grant_real_access(): void
    {
        $owner = $this->developer();
        $invited = $this->developer();
        $wrong = $this->developer();
        $this->app($owner);
        $token = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/developer/team/invitations', ['email' => $invited->email, 'role' => 'developer'])->assertCreated()->json('data.token');
        $this->actingAs($wrong, 'sanctum')->postJson('/api/v1/developer/team/accept', ['token' => $token])->assertForbidden();
        $this->actingAs($invited, 'sanctum')->postJson('/api/v1/developer/team/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/developer/team/accept', ['token' => $token])->assertNotFound();
        $this->withHeader('X-Workspace-Id', $owner->id)->getJson('/api/v1/developer/apps')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_two_factor_enrollment_verifies_codes_and_prevents_replay(): void
    {
        $u = $this->developer();
        $this->actingAs($u, 'sanctum');
        $secret = $this->postJson('/api/v1/developer/two-factor/setup', ['password' => 'password'])->assertOk()->json('data.secret');
        $code = app(DeveloperTotp::class)->code($secret, intdiv(time(), 30));
        $recovery = $this->postJson('/api/v1/developer/two-factor/confirm', ['code' => $code])->assertOk()->assertJsonCount(8, 'data.recovery_codes')->json('data.recovery_codes');
        $this->getJson('/api/v1/developer/preferences')->assertOk()->assertJsonPath('data.two_factor_enabled', true);
        $this->assertFalse(app(DeveloperTotp::class)->verify($u->id, $code));
        $this->assertTrue(app(DeveloperTotp::class)->verify($u->id, $recovery[0]));
        $this->assertFalse(app(DeveloperTotp::class)->verify($u->id, $recovery[0]));
    }

    public function test_totp_matches_rfc6238_sha1_vector(): void
    {
        $this->assertSame('287082', app(DeveloperTotp::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }

    public function test_sign_in_requires_second_factor_before_issuing_tokens(): void
    {
        Mail::fake();
        $this->app->instance(FirebaseAuthService::class, \Mockery::mock(FirebaseAuthService::class));
        $u = $this->developer();
        $secret = app(DeveloperTotp::class)->secret();
        DB::table('developer_preferences')->insert(['user_id' => $u->id, 'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        // The default factory password is shorter than this API's minimum, so set an explicit password.
        $u->forceFill(['password' => Hash::make('password123')])->save();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password123'])->assertUnprocessable()->assertJsonPath('requires_two_factor', true);
        $this->assertSame(0, $u->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password123', 'otp' => app(DeveloperTotp::class)->code($secret, intdiv(time(), 30))])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_browser_login_enforces_opt_in_two_factor_and_accepts_recovery_code(): void
    {
        $u = $this->developer();
        $secret = app(DeveloperTotp::class)->secret();
        DB::table('developer_preferences')->insert(['user_id' => $u->id, 'totp_secret' => Crypt::encryptString($secret), 'totp_confirmed_at' => now(), 'recovery_codes' => json_encode([Hash::make('recovery-test')]), 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('otp');
        $this->assertGuest('web');
        $this->post('/login', ['email' => $u->email, 'password' => 'password', 'otp' => 'recovery-test'])->assertRedirect();
        $this->assertAuthenticatedAs($u, 'web');
    }

    public function test_social_login_cannot_bypass_opt_in_two_factor(): void
    {
        $u = $this->developer();
        $u->forceFill(['provider' => 'google', 'provider_id' => 'firebase-user'])->save();
        $firebase = \Mockery::mock(FirebaseAuthService::class);
        $firebase->shouldReceive('getUserData')->andReturn(['provider_id' => 'firebase-user', 'email' => $u->email, 'name' => $u->name, 'avatar' => null]);
        $this->app->instance(FirebaseAuthService::class, $firebase);
        DB::table('developer_preferences')->insert(['user_id' => $u->id, 'totp_secret' => Crypt::encryptString(app(DeveloperTotp::class)->secret()), 'totp_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/auth/social-login', ['token' => 'verified-firebase-token', 'provider' => 'google'])->assertUnprocessable()->assertJsonPath('requires_two_factor', true);
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_api_keys_are_read_only_and_cannot_access_account_routes(): void
    {
        $u = $this->developer();
        $key = $u->createToken('developer-key:Read', ['developer:read'], now()->addDay());
        $this->withToken($key->plainTextToken)->getJson('/api/v1/developer/apps')->assertOk();
        $this->withToken($key->plainTextToken)->postJson('/api/v1/developer/apps', ['name' => 'Blocked'])->assertForbidden();
        $this->withToken($key->plainTextToken)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_revoked_bearer_cannot_fall_back_to_authenticated_cookie(): void
    {
        $u = $this->developer();
        $this->actingAs($u, 'web')->withToken('123|revoked')->getJson('/api/v1/developer/dashboard')->assertUnauthorized();
    }

    public function test_webhooks_reject_private_destinations_and_keep_secrets_private(): void
    {
        Queue::fake();
        $u = $this->developer();
        $this->actingAs($u, 'sanctum');
        $this->postJson('/api/v1/developer/webhooks', ['name' => 'Private', 'url' => 'https://127.0.0.1/secret', 'events' => ['application.created']])->assertUnprocessable();
        $response = $this->postJson('/api/v1/developer/webhooks', ['name' => 'Public', 'url' => 'https://93.184.216.34/webhook', 'events' => ['application.created']])->assertCreated();
        $id = $response->json('data.id');
        $this->assertNotNull($response->json('data.secret'));
        $this->getJson('/api/v1/developer/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->postJson('/api/v1/developer/webhooks/'.$id.'/test')->assertStatus(202);
        Queue::assertPushed(DeliverDeveloperWebhook::class);
    }

    public function test_webhook_delivery_signs_exact_body_and_records_result(): void
    {
        $u = $this->developer();
        $secret = 'test-signing-secret';
        $hook = DB::table('developer_webhooks')->insertGetId(['owner_id' => $u->id, 'name' => 'Test', 'url' => 'https://93.184.216.34/webhook', 'secret' => Crypt::encryptString($secret), 'events' => '["webhook.test"]', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('developer_webhook_deliveries')->insertGetId(['webhook_id' => $hook, 'event' => 'webhook.test', 'payload' => '{"event":"webhook.test"}', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(function ($request) use ($secret) {
            $signature = $request->header('X-Kulsah-Signature')[0];
            preg_match('/t=(\d+),v1=(\w+)/', $signature, $parts);
            $this->assertSame(hash_hmac('sha256', $parts[1].'.'.$request->body(), $secret), $parts[2]);

            return Http::response('', 204);
        });
        (new DeliverDeveloperWebhook($id))->handle(app(DeveloperWebhookUrl::class));
        $this->assertDatabaseHas('developer_webhook_deliveries', ['id' => $id, 'status' => 'delivered', 'response_status' => 204]);
    }

    public function test_account_deletion_revokes_owned_apps_and_grants(): void
    {
        $this->app->instance(FirebaseAuthService::class, \Mockery::mock(FirebaseAuthService::class));
        $u = $this->developer();
        $app = $this->app($u);
        DB::table('oauth_access_tokens')->insert(['id' => 'delete-test', 'user_id' => $u->id, 'client_id' => $app->id, 'scopes' => '[]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u, 'sanctum')->deleteJson('/api/v1/auth/account', ['confirmation' => 'DELETE', 'password' => 'password'])->assertOk();
        $this->assertDatabaseHas('oauth_clients', ['id' => $app->id, 'revoked' => true]);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'delete-test', 'revoked' => true]);
        $this->assertSame('Deleted user', $u->fresh()->name);
    }

    public function test_revoking_a_paired_device_also_revokes_its_refresh_token(): void
    {
        $u=$this->developer();$access=$u->createToken('Device|pair-access',['*'],now()->addHour());$refresh=$u->createToken('Device|pair-refresh',['refresh'],now()->addDay());
        $this->actingAs($u,'sanctum')->deleteJson('/api/v1/developer/sessions/token:'.$access->accessToken->id)->assertOk();
        $this->assertNull(PersonalAccessToken::find($access->accessToken->id));$this->assertNull(PersonalAccessToken::find($refresh->accessToken->id));
    }

    public function test_security_sessions_and_notifications_are_real_user_records(): void
    {
        $u = $this->developer();
        $other = $this->developer();
        $own = $u->createToken('test-access', ['*'], now()->addHour());
        $foreign = $other->createToken('test-access', ['*'], now()->addHour());
        DB::table('developer_notifications')->insert(['user_id' => $u->id, 'title' => 'Real alert', 'message' => 'Secret rotated', 'created_at' => now()]);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/developer/sessions')->assertOk()->assertJsonCount(1,'data');
        $this->deleteJson('/api/v1/developer/sessions/token:'.$foreign->accessToken->id)->assertNotFound();
        $this->getJson('/api/v1/developer/notifications')->assertOk()->assertJsonPath('unread',1);
        $this->patchJson('/api/v1/developer/notifications/read')->assertOk();
        $this->getJson('/api/v1/developer/notifications')->assertJsonPath('unread',0);
    }
}
