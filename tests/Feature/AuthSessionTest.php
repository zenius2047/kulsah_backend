<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_token_is_rotated_into_a_new_session(): void
    {
        $user = User::factory()->create();
        $refresh = $user->createToken('test-refresh', ['refresh'], now()->addDay());
        $oldTokenId = $refresh->accessToken->id;

        $response = $this->withToken($refresh->plainTextToken)
            ->postJson('/api/v1/auth/refresh');

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
        $this->assertNull(PersonalAccessToken::find($oldTokenId));
        $this->assertCount(2, $user->fresh()->tokens);
    }

    public function test_access_token_cannot_be_used_as_a_refresh_token(): void
    {
        $user = User::factory()->create();
        $access = $user->createToken('test-access', ['*'], now()->addHour());

        $this->withToken($access->plainTextToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertForbidden();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $access = $user->createToken('test-access', ['*'], now()->addHour());
        $user->createToken('test-refresh', ['refresh'], now()->addDay());

        $this->withToken($access->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertCount(0, $user->fresh()->tokens);
    }
}
