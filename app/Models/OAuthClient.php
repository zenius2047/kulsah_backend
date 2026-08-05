<?php

namespace App\Models;

use App\Enums\OAuthClientType;
use Laravel\Passport\Client as PassportClient;

class OAuthClient extends PassportClient
{
    protected $casts = [
        'grant_types' => 'array',
        'scopes' => 'array',
        'redirect_uris' => 'array',
        'android_package_names' => 'array',
        'android_sha256_cert_fingerprints' => 'array',
        'ios_universal_link_domains' => 'array',
        'ios_custom_url_schemes' => 'array',
        'personal_access_client' => 'bool',
        'password_client' => 'bool',
        'revoked' => 'bool',
        'client_type' => OAuthClientType::class,
    ];

    public function isWebClient(): bool
    {
        return $this->client_type === OAuthClientType::Web;
    }

    public function isAndroidClient(): bool
    {
        return $this->client_type === OAuthClientType::Android;
    }

    public function isIosClient(): bool
    {
        return $this->client_type === OAuthClientType::Ios;
    }

    public function isPublicClient(): bool
    {
        return ! $this->confidential();
    }

    public function clientTypeLabel(): string
    {
        return $this->client_type?->label() ?? OAuthClientType::Web->label();
    }
}
