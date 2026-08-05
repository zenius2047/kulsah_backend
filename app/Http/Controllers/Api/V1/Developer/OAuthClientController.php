<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\OAuthClientType;
use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class OAuthClientController extends Controller
{
    public function index(Request $request)
    {
        $clientType = $this->resolveClientType($request->query('client_type'), false);

        $clients = OAuthClient::query()
            ->where('owner_type', User::class)
            ->where('owner_id', $request->user()->id)
            ->where('revoked', false)
            ->when($clientType !== null, fn ($query) => $query->where('client_type', $clientType->value))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Client $client) => $this->presentClient($client));

        return response()->json([
            'data' => $clients,
            'meta' => [
                'count' => $clients->count(),
            ],
        ]);
    }

    public function show(Request $request, Client $client)
    {
        abort_unless(
            $client->owner_type === User::class && (string) $client->owner_id === (string) $request->user()->id,
            404
        );

        return response()->json([
            'data' => $this->presentClient($client),
        ]);
    }

    public function store(Request $request)
    {
        $clientType = $this->resolveClientType($request->input('client_type'));
        $validated = $this->validatePayload($request, $clientType);

        $client = DB::transaction(function () use ($request, $validated, $clientType) {
            return OAuthClient::forceCreate([
                'owner_type' => User::class,
                'owner_id' => $request->user()->id,
                'name' => $validated['name'],
                'secret' => $validated['secret'],
                'provider' => config('auth.guards.api.provider', 'users'),
                'logo' => $validated['logo'] ?? null,
                'description' => $validated['description'] ?? null,
                'allowed_origins' => isset($validated['allowed_origins'])
                    ? json_encode($validated['allowed_origins'], JSON_UNESCAPED_SLASHES)
                    : null,
                'client_type' => $clientType->value,
                'redirect_uris' => $validated['redirect_uris'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'android_package_names' => $validated['android_package_names'] ?? null,
                'android_sha256_cert_fingerprints' => $validated['android_sha256_cert_fingerprints'] ?? null,
                'ios_bundle_id' => $validated['ios_bundle_id'] ?? null,
                'ios_team_id' => $validated['ios_team_id'] ?? null,
                'ios_universal_link_domains' => $validated['ios_universal_link_domains'] ?? null,
                'ios_custom_url_schemes' => $validated['ios_custom_url_schemes'] ?? null,
                'revoked' => false,
            ]);
        });

        return response()->json([
            'message' => 'OAuth client created successfully.',
            'data' => $this->presentClientWithSecret($client),
        ], 201);
    }

    public function regenerateSecret(Request $request, Client $client, ClientRepository $clients)
    {
        abort_unless(
            $client->owner_type === User::class && (string) $client->owner_id === (string) $request->user()->id,
            404
        );

        abort_if($this->isPublicClient($client), 422, 'Public clients do not use client secrets.');

        $clients->regenerateSecret($client);

        return response()->json([
            'message' => 'OAuth client secret regenerated successfully.',
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'secret' => $client->plainSecret,
            ],
        ]);
    }

    public function destroy(Request $request, Client $client, ClientRepository $clients)
    {
        abort_unless(
            $client->owner_type === User::class && (string) $client->owner_id === (string) $request->user()->id,
            404
        );

        $clients->delete($client);

        return response()->json([
            'message' => 'OAuth client revoked successfully.',
        ]);
    }

    private function validatePayload(Request $request, OAuthClientType $clientType): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('oauth_clients', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('owner_type', User::class)
                        ->where('owner_id', $request->user()->id)
                        ->where('revoked', false);
                }),
            ],
            'client_type' => ['sometimes', Rule::in(OAuthClientType::values())],
            'logo' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['required_with:allowed_origins', 'string', 'url'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', $this->redirectUriRule($clientType)],
            'confidential' => ['sometimes', 'boolean'],
        ];

        if ($clientType === OAuthClientType::Android) {
            $rules['android_package_names'] = ['required', 'array', 'min:1'];
            $rules['android_package_names.*'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'];
            $rules['android_sha256_cert_fingerprints'] = ['required', 'array', 'min:1'];
            $rules['android_sha256_cert_fingerprints.*'] = ['required', 'string', 'regex:/^([A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}$/'];
        }

        if ($clientType === OAuthClientType::Ios) {
            $rules['ios_bundle_id'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'];
            $rules['ios_team_id'] = ['required', 'string', 'regex:/^[A-Z0-9]{10}$/'];
            $rules['ios_universal_link_domains'] = ['nullable', 'array'];
            $rules['ios_universal_link_domains.*'] = ['required_with:ios_universal_link_domains', 'string', 'regex:/^(?=.{1,253}$)(?:\*\.)?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/'];
            $rules['ios_custom_url_schemes'] = ['nullable', 'array'];
            $rules['ios_custom_url_schemes.*'] = ['required_with:ios_custom_url_schemes', 'string', 'regex:/^[a-z][a-z0-9+.-]*$/i'];
        }

        $validated = $request->validate($rules);

        $validated['redirect_uris'] = $this->normalizeArray($validated['redirect_uris']);
        $this->assertNoDuplicates($validated['redirect_uris'], 'redirect_uris');

        foreach (['android_package_names', 'android_sha256_cert_fingerprints', 'ios_universal_link_domains', 'ios_custom_url_schemes'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = $this->normalizeArray($validated[$field]);
                $this->assertNoDuplicates($validated[$field], $field);
            }
        }

        if ($clientType->isMobile()) {
            $validated['secret'] = null;
        } else {
            $validated['secret'] = $request->boolean('confidential', true) ? Str::random(40) : null;
        }

        return $validated;
    }

    private function redirectUriRule(OAuthClientType $clientType): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($clientType): void {
            $redirectUri = (string) $value;

            if (str_contains($redirectUri, '*')) {
                $fail('Wildcard redirect URIs are not allowed.');

                return;
            }

            $parts = parse_url($redirectUri);

            if ($parts === false || ! isset($parts['scheme'])) {
                $fail('The redirect URI must be a valid URI.');

                return;
            }

            if ($clientType === OAuthClientType::Web) {
                if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
                    $fail('The redirect URI must be a valid URL.');
                }

                return;
            }

            $scheme = strtolower((string) $parts['scheme']);

            if (in_array($scheme, ['http', 'https'], true)) {
                if (empty($parts['host'])) {
                    $fail('The redirect URI must contain a host.');
                }

                return;
            }

            if (! preg_match('/^[a-z][a-z0-9+.-]*$/i', $scheme)) {
                $fail('The redirect URI scheme is invalid.');
            }
        };
    }

    private function resolveClientType(mixed $value, bool $defaultToWeb = true): ?OAuthClientType
    {
        if ($value === null || $value === '') {
            return $defaultToWeb ? OAuthClientType::Web : null;
        }

        $clientType = OAuthClientType::tryFrom((string) $value);

        if ($clientType === null) {
            throw ValidationException::withMessages([
                'client_type' => 'The selected client type is invalid.',
            ]);
        }

        return $clientType;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
        }

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    private function clientType(Client $client): OAuthClientType
    {
        $rawClientType = $client->getAttribute('client_type');

        if ($rawClientType instanceof OAuthClientType) {
            return $rawClientType;
        }

        if (is_string($rawClientType)) {
            return OAuthClientType::tryFrom($rawClientType) ?? OAuthClientType::Web;
        }

        return OAuthClientType::Web;
    }

    private function isPublicClient(Client $client): bool
    {
        return empty($client->getAttribute('secret'));
    }

    private function assertNoDuplicates(array $values, string $field): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages([
                $field => 'The ' . str_replace('_', ' ', $field) . ' field contains duplicate values.',
            ]);
        }
    }

    private function presentClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'owner_type' => $client->owner_type,
            'owner_id' => $client->owner_id,
            'client_type' => $this->clientType($client)->value,
            'client_type_label' => $this->clientType($client)->label(),
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'provider' => $client->provider,
            'logo' => $client->logo,
            'description' => $client->description,
            'allowed_origins' => $this->normalizeList($client->allowed_origins),
            'android_package_names' => $this->normalizeList($client->getAttribute('android_package_names')),
            'android_sha256_cert_fingerprints' => $this->normalizeList($client->getAttribute('android_sha256_cert_fingerprints')),
            'ios_bundle_id' => $client->getAttribute('ios_bundle_id'),
            'ios_team_id' => $client->getAttribute('ios_team_id'),
            'ios_universal_link_domains' => $this->normalizeList($client->getAttribute('ios_universal_link_domains')),
            'ios_custom_url_schemes' => $this->normalizeList($client->getAttribute('ios_custom_url_schemes')),
            'revoked' => $client->revoked,
            'is_confidential' => $client->confidential(),
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];
    }

    private function presentClientWithSecret(Client $client): array
    {
        return [
            ...$this->presentClient($client),
            'secret' => $client->plainSecret,
        ];
    }

    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [$value];
        }

        return [];
    }
}
