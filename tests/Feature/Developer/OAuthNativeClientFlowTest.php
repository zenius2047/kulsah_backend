<?php

namespace Tests\Feature\Developer;

use App\Enums\OAuthClientType;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OAuthNativeClientFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_public_clients_can_complete_pkce_authorization_code_flow(): void
    {
        foreach ([OAuthClientType::Android, OAuthClientType::Ios] as $clientType) {
            $user = User::factory()->create([
                'username' => Str::slug($clientType->value) . '_oauth_user',
            ]);

            $client = $this->createNativeClient($user, $clientType);
            $verifier = Str::random(64);
            $challenge = $this->pkceChallenge($verifier);

            $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => '',
                'state' => 'state-' . $clientType->value,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]))
                ->assertOk();

            $authorizeResponse = $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => '',
                'state' => 'state-' . $clientType->value,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]));

            $authorizeResponse->assertOk();

            $authToken = $this->extractAuthToken($authorizeResponse->getContent());
            $approvalResponse = $this->actingAs($user, 'web')->post(route('passport.authorizations.approve'), [
                'auth_token' => $authToken,
            ]);

            $approvalResponse->assertRedirect();

            $redirectUri = $approvalResponse->headers->get('Location');
            $query = [];
            parse_str((string) parse_url($redirectUri, PHP_URL_QUERY), $query);

            $this->assertSame('state-' . $clientType->value, $query['state'] ?? null);
            $this->assertNotEmpty($query['code'] ?? null);

            $tokenResponse = $this->post('/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $client->id,
                'redirect_uri' => $client->redirect_uris[0],
                'code' => $query['code'],
                'code_verifier' => $verifier,
            ]);

            $tokenResponse->assertOk()
                ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);

            $this->assertDatabaseHas('oauth_access_tokens', [
                'client_id' => $client->id,
                'revoked' => false,
            ]);
        }
    }

    public function test_public_clients_cannot_regenerate_client_secrets(): void
    {
        $user = User::factory()->create([
            'username' => 'public_secret_test',
        ]);

        $client = $this->createNativeClient($user, OAuthClientType::Android);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/developer/apps/{$client->id}/regenerate-secret")
            ->assertStatus(422);
    }

    public function test_wildcard_redirect_uris_are_rejected(): void
    {
        $user = User::factory()->create([
            'username' => 'wildcard_redirect_test',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/developer/apps', [
                'name' => 'Wildcard App',
                'client_type' => OAuthClientType::Android->value,
                'redirect_uris' => ['https://*.example.com/oauth/callback'],
                'android_package_names' => ['com.kulsah.app'],
                'android_sha256_cert_fingerprints' => ['AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['redirect_uris.0']);
    }

    public function test_public_clients_require_pkce_at_authorization_time(): void
    {
        $user = User::factory()->create([
            'username' => 'pkce_required_test',
        ]);

        $client = $this->createNativeClient($user, OAuthClientType::Android);

        $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => '',
            'state' => 'missing-pkce',
        ]))
            ->assertStatus(400);
    }

    public function test_authorization_codes_cannot_be_reused_or_used_after_expiration(): void
    {
        $user = User::factory()->create([
            'username' => 'code_replay_test',
        ]);

        $originalTokenTtl = Passport::tokensExpireIn();
        $this->setAuthCodeTtl(new \DateInterval('PT1S'));

        $expiredClient = $this->createNativeClient($user, OAuthClientType::Ios);
        $expiredVerifier = Str::random(64);
        $expiredChallenge = $this->pkceChallenge($expiredVerifier);

        $expiredAuthorizeResponse = $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
            'client_id' => $expiredClient->id,
            'redirect_uri' => $expiredClient->redirect_uris[0],
            'response_type' => 'code',
            'scope' => '',
            'state' => 'expired-state',
            'code_challenge' => $expiredChallenge,
            'code_challenge_method' => 'S256',
        ]));

        $expiredAuthToken = $this->extractAuthToken($expiredAuthorizeResponse->getContent());

        $expiredApprovalResponse = $this->actingAs($user, 'web')->post(route('passport.authorizations.approve'), [
            'auth_token' => $expiredAuthToken,
        ]);

        $expiredLocation = $expiredApprovalResponse->headers->get('Location');
        $expiredQuery = [];
        parse_str((string) parse_url($expiredLocation, PHP_URL_QUERY), $expiredQuery);
        $expiredCode = $expiredQuery['code'];

        sleep(2);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $expiredClient->id,
            'redirect_uri' => $expiredClient->redirect_uris[0],
            'code' => $expiredCode,
            'code_verifier' => $expiredVerifier,
        ])->assertStatus(400);

        $this->setAuthCodeTtl($originalTokenTtl);

        $replayClient = $this->createNativeClient($user, OAuthClientType::Android);
        $replayVerifier = Str::random(64);
        $replayChallenge = $this->pkceChallenge($replayVerifier);

        $replayAuthorizeResponse = $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
            'client_id' => $replayClient->id,
            'redirect_uri' => $replayClient->redirect_uris[0],
            'response_type' => 'code',
            'scope' => '',
            'state' => 'replay-state',
            'code_challenge' => $replayChallenge,
            'code_challenge_method' => 'S256',
        ]));

        $replayAuthToken = $this->extractAuthToken($replayAuthorizeResponse->getContent());

        $replayApprovalResponse = $this->actingAs($user, 'web')->post(route('passport.authorizations.approve'), [
            'auth_token' => $replayAuthToken,
        ]);

        $replayLocation = $replayApprovalResponse->headers->get('Location');
        $replayQuery = [];
        parse_str((string) parse_url($replayLocation, PHP_URL_QUERY), $replayQuery);
        $replayCode = $replayQuery['code'];

        $successfulReplayResponse = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $replayClient->id,
            'redirect_uri' => $replayClient->redirect_uris[0],
            'code' => $replayCode,
            'code_verifier' => $replayVerifier,
        ]);

        $successfulReplayResponse
            ->assertOk()
            ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $replayClient->id,
            'redirect_uri' => $replayClient->redirect_uris[0],
            'code' => $replayCode,
            'code_verifier' => $replayVerifier,
        ])->assertStatus(400);
    }

    private function createNativeClient(User $user, OAuthClientType $clientType): OAuthClient
    {
        $payload = [
            'name' => ucfirst($clientType->value) . ' Native App',
            'client_type' => $clientType->value,
            'confidential' => false,
            'redirect_uris' => match ($clientType) {
                OAuthClientType::Android => ['com.kulsah.app:/oauth/callback'],
                OAuthClientType::Ios => ['com.kulsah.ios:/oauth/callback'],
                default => ['https://example.com/oauth/callback'],
            },
        ];

        if ($clientType === OAuthClientType::Android) {
            $payload['android_package_names'] = ['com.kulsah.app', 'com.kulsah.app.beta'];
            $payload['android_sha256_cert_fingerprints'] = [
                'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99',
            ];
        }

        if ($clientType === OAuthClientType::Ios) {
            $payload['ios_bundle_id'] = 'com.kulsah.ios';
            $payload['ios_team_id'] = 'A1B2C3D4E5';
            $payload['ios_universal_link_domains'] = ['app.kulsah.com'];
            $payload['ios_custom_url_schemes'] = ['com.kulsah.ios'];
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/developer/apps', $payload);

        $response->assertCreated();

        return OAuthClient::query()->findOrFail($response->json('data.id'));
    }

    private function extractAuthToken(string $html): string
    {
        preg_match('/name="auth_token" value="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'Expected an auth token in the authorization form.');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function setAuthCodeTtl(\DateInterval $ttl): void
    {
        $server = app(\League\OAuth2\Server\AuthorizationServer::class);
        $serverReflection = new \ReflectionObject($server);
        $enabledGrantTypes = $serverReflection->getProperty('enabledGrantTypes');
        $enabledGrantTypes->setAccessible(true);
        $grants = $enabledGrantTypes->getValue($server);

        if (! isset($grants['authorization_code'])) {
            return;
        }

        $grantReflection = new \ReflectionObject($grants['authorization_code']);
        $authCodeTtl = $grantReflection->getProperty('authCodeTTL');
        $authCodeTtl->setAccessible(true);
        $authCodeTtl->setValue($grants['authorization_code'], $ttl);
    }
}
