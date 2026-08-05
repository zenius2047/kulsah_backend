<?php

namespace Tests\Feature\Developer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class OAuthClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_can_create_list_view_and_revoke_oauth_clients(): void
    {
        $user = User::factory()->create([
            'username' => 'dev_user',
        ]);

        $createResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/developer/apps', [
                'name' => 'Mobile App',
                'logo' => 'https://cdn.example.com/apps/mobile-app.png',
                'description' => 'Mobile app for creators and fans.',
                'allowed_origins' => [
                    'https://app.example.com',
                    'https://admin.example.com',
                ],
                'redirect_uris' => [
                    'https://example.com/oauth/callback',
                    'https://example.com/oauth/return',
                ],
                'confidential' => true,
            ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('message', 'OAuth client created successfully.')
            ->assertJsonPath('data.name', 'Mobile App')
            ->assertJsonPath('data.logo', 'https://cdn.example.com/apps/mobile-app.png')
            ->assertJsonPath('data.description', 'Mobile app for creators and fans.')
            ->assertJsonPath('data.allowed_origins.0', 'https://app.example.com')
            ->assertJsonPath('data.redirect_uris.0', 'https://example.com/oauth/callback')
            ->assertJsonPath('data.is_confidential', true);

        $clientId = $createResponse->json('data.id');

        $this->assertNotNull($createResponse->json('data.secret'));

        $client = Client::query()->findOrFail($clientId);

        $showResponse = $this
            ->actingAs($user, 'sanctum')
            ->getJson("/api/v1/developer/apps/{$clientId}");

        $showResponse
            ->assertOk()
            ->assertJsonPath('data.id', $clientId)
            ->assertJsonPath('data.name', 'Mobile App')
            ->assertJsonPath('data.logo', 'https://cdn.example.com/apps/mobile-app.png')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.is_confidential', true);

        $listResponse = $this
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/developer/apps');

        $listResponse
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $clientId)
            ->assertJsonPath('data.0.name', 'Mobile App')
            ->assertJsonMissingPath('data.0.secret')
            ->assertJsonPath('data.0.allowed_origins.1', 'https://admin.example.com');

        $regenerateResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson("/api/v1/developer/apps/{$clientId}/regenerate-secret");

        $regenerateResponse
            ->assertOk()
            ->assertJsonPath('message', 'OAuth client secret regenerated successfully.')
            ->assertJsonPath('data.id', $clientId)
            ->assertJsonPath('data.name', 'Mobile App')
            ;

        $this->assertNotNull($regenerateResponse->json('data.secret'));

        $revokeResponse = $this
            ->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/developer/apps/{$clientId}");

        $revokeResponse
            ->assertOk()
            ->assertJsonPath('message', 'OAuth client revoked successfully.');

        $this->assertDatabaseHas('oauth_clients', [
            'id' => $clientId,
            'revoked' => true,
        ]);
    }

    public function test_developer_cannot_create_duplicate_client_names(): void
    {
        $user = User::factory()->create([
            'username' => 'dev_user_2',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/developer/apps', [
            'name' => 'Dashboard App',
            'logo' => 'https://cdn.example.com/apps/dashboard.png',
            'description' => 'Dashboard app.',
            'allowed_origins' => ['https://dashboard.example.com'],
            'redirect_uris' => ['https://example.com/oauth/callback'],
            'confidential' => true,
        ])->assertCreated();

        $duplicateResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/developer/apps', [
                'name' => 'Dashboard App',
                'logo' => 'https://cdn.example.com/apps/dashboard.png',
                'description' => 'Dashboard app.',
                'allowed_origins' => ['https://dashboard.example.com'],
                'redirect_uris' => ['https://example.com/oauth/callback'],
                'confidential' => true,
            ]);

        $duplicateResponse
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_secret_is_not_returned_in_list_or_show_responses(): void
    {
        $user = User::factory()->create([
            'username' => 'dev_user_3',
        ]);

        $createResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/developer/apps', [
                'name' => 'Secretless App',
                'redirect_uris' => ['https://example.com/oauth/callback'],
                'confidential' => true,
            ]);

        $clientId = $createResponse->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/developer/apps')
            ->assertOk()
            ->assertJsonMissingPath('data.0.secret');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/developer/apps/{$clientId}")
            ->assertOk()
            ->assertJsonMissingPath('data.secret');
    }
}
