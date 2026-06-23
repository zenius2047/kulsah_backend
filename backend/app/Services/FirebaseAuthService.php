<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use RuntimeException;

class FirebaseAuthService
{
    protected $auth;

    public function __construct()
    {
        $credentialsPath = base_path(config('services.firebase.credentials'));

        if (! file_exists($credentialsPath)) {
            throw new RuntimeException("Firebase credentials file not found at [{$credentialsPath}].");
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || ! isset($credentials['type'], $credentials['project_id'], $credentials['client_email'], $credentials['private_key'])) {
            throw new RuntimeException(
                "Firebase credentials at [{$credentialsPath}] must be a Firebase service-account JSON file. ".
                "The current file looks like a client config or is incomplete."
            );
        }

        $factory = (new Factory)->withServiceAccount($credentialsPath);

        $this->auth = $factory->createAuth();
    }

    /**
     * Verify Firebase ID Token
     */
    public function verifyToken(string $token)
    {
        return $this->auth->verifyIdToken($token);
    }

    /**
     * Extract normalized user data from Firebase
     */
    public function getUserData(string $token): array
    {
        $verified = $this->verifyToken($token);

        return [
            'provider_id' => $verified->claims()->get('sub'), // Firebase UID
            'email'       => $verified->claims()->get('email'),
            'name'        => $verified->claims()->get('name'),
            'avatar'      => $verified->claims()->get('picture'),
        ];
    }
}
