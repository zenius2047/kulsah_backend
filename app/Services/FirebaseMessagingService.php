<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\Messaging;
use RuntimeException;

class FirebaseMessagingService
{
    private ?Messaging $messaging = null;

    public function messaging(): Messaging
    {
        if ($this->messaging instanceof Messaging) {
            return $this->messaging;
        }

        $credentialsPath = base_path(config('services.firebase.credentials'));

        if (! file_exists($credentialsPath)) {
            throw new RuntimeException("Firebase credentials file not found at [{$credentialsPath}].");
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || ! isset($credentials['type'], $credentials['project_id'], $credentials['client_email'], $credentials['private_key'])) {
            throw new RuntimeException(
                "Firebase credentials at [{$credentialsPath}] must be a Firebase service-account JSON file. ".
                'The current file looks like a client config or is incomplete.'
            );
        }

        $factory = (new Factory)->withServiceAccount($credentialsPath);

        return $this->messaging = $factory->createMessaging();
    }
}
