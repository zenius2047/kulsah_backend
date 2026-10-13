<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class DeveloperWebhookUrl
{
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            $this->invalid();
        }
        $host = $parts['host'];
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_column(dns_get_record($host, DNS_A) ?: [], 'ip');
        if (! $addresses) {
            $this->invalid();
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_contains($ip, ':')) {
                $this->invalid();
            }
        }

        // Resolve and pin a public IPv4 address on every attempt; never follow redirects.
        return ['host' => $host, 'ip' => $addresses[0], 'port' => 443];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['url' => 'Webhooks require an HTTPS endpoint on port 443 with a public IPv4 address. Private networks and redirects are not allowed.']);
    }
}
