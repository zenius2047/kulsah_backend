<?php

namespace Tests\Feature;

use App\Enums\OAuthClientType;
use App\Models\OAuthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebAuthConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_mobile_oauth_consent_context_when_intended_url_is_a_native_client_authorize_request(): void
    {
        foreach ([OAuthClientType::Android, OAuthClientType::Ios] as $clientType) {
            $client = $this->createNativeClient($clientType);

            $intendedUrl = url('/oauth/authorize?' . http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => '',
                'state' => 'consent-test-' . $clientType->value,
            ]));

            $this->withSession([
                'url.intended' => $intendedUrl,
            ])
                ->get('/login')
                ->assertOk()
                ->assertSee('Mobile sign-in request')
                ->assertSee($client->name)
                ->assertSee($clientType->label())
                ->assertSee($client->redirect_uris[0]);
        }
    }

    private function createNativeClient(OAuthClientType $clientType): OAuthClient
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'name' => ucfirst($clientType->value) . ' Consent App',
            'client_type' => $clientType->value,
            'owner_type' => null,
            'owner_id' => null,
            'provider' => config('auth.guards.api.provider', 'users'),
            'secret' => null,
            'redirect_uris' => match ($clientType) {
                OAuthClientType::Android => ['com.kulsah.app:/oauth/callback'],
                OAuthClientType::Ios => ['com.kulsah.ios:/oauth/callback'],
                default => ['https://example.com/oauth/callback'],
            },
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ];

        if ($clientType === OAuthClientType::Android) {
            $payload['android_package_names'] = ['com.kulsah.app'];
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

        return OAuthClient::forceCreate($payload);
    }
}
