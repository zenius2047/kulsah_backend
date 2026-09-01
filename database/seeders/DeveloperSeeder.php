<?php

namespace Database\Seeders;

use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeveloperSeeder extends Seeder
{
    public const WEB_CLIENT_ID = '33000000-0000-4000-8000-000000000001';

    public const WEB_CLIENT_SECRET = 'DemoClientSecret@123';

    public function run(): void
    {
        DB::transaction(function (): void {
            $owner = User::query()->where('username', 'admin')->firstOrFail();

            $clients = [
                [
                    'id' => self::WEB_CLIENT_ID,
                    'name' => 'Kulsah Demo Web App',
                    'secret' => self::WEB_CLIENT_SECRET,
                    'client_type' => 'web',
                    'redirect_uris' => ['http://localhost:3000/auth/callback'],
                    'allowed_origins' => ['http://localhost:3000'],
                    'description' => 'Confidential local web client for exercising the OAuth developer flow.',
                ],
                [
                    'id' => '33000000-0000-4000-8000-000000000002',
                    'name' => 'Kulsah Demo Android App',
                    'secret' => null,
                    'client_type' => 'android',
                    'redirect_uris' => ['com.kulsah.demo:/oauth/callback'],
                    'allowed_origins' => null,
                    'android_package_names' => ['com.kulsah.demo'],
                    'android_sha256_cert_fingerprints' => ['AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99'],
                    'description' => 'Public Android PKCE client for local mobile integration testing.',
                ],
                [
                    'id' => '33000000-0000-4000-8000-000000000003',
                    'name' => 'Kulsah Demo iOS App',
                    'secret' => null,
                    'client_type' => 'ios',
                    'redirect_uris' => ['kulsahdemo:/oauth/callback'],
                    'allowed_origins' => null,
                    'ios_bundle_id' => 'com.kulsah.demo',
                    'ios_team_id' => 'KULSAH1234',
                    'ios_universal_link_domains' => ['demo.kulsah.test'],
                    'ios_custom_url_schemes' => ['kulsahdemo'],
                    'description' => 'Public iOS PKCE client for local mobile integration testing.',
                ],
            ];

            foreach ($clients as $definition) {
                OAuthClient::query()->updateOrCreate(
                    ['id' => $definition['id']],
                    [
                        'owner_type' => User::class,
                        'owner_id' => $owner->id,
                        'name' => $definition['name'],
                        'secret' => $definition['secret'],
                        'provider' => 'users',
                        'logo' => 'https://picsum.photos/seed/'.strtolower(str_replace(' ', '-', $definition['name'])).'/256/256',
                        'description' => $definition['description'],
                        'allowed_origins' => isset($definition['allowed_origins'])
                            ? json_encode($definition['allowed_origins'], JSON_UNESCAPED_SLASHES)
                            : null,
                        'client_type' => $definition['client_type'],
                        'redirect_uris' => $definition['redirect_uris'],
                        'grant_types' => ['authorization_code', 'refresh_token'],
                        'android_package_names' => $definition['android_package_names'] ?? null,
                        'android_sha256_cert_fingerprints' => $definition['android_sha256_cert_fingerprints'] ?? null,
                        'ios_bundle_id' => $definition['ios_bundle_id'] ?? null,
                        'ios_team_id' => $definition['ios_team_id'] ?? null,
                        'ios_universal_link_domains' => $definition['ios_universal_link_domains'] ?? null,
                        'ios_custom_url_schemes' => $definition['ios_custom_url_schemes'] ?? null,
                        'revoked' => false,
                    ],
                );
            }
        });
    }
}
