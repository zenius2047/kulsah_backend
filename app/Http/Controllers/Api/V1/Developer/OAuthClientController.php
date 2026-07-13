<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class OAuthClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->where('owner_type', User::class)
            ->where('owner_id', $request->user()->id)
            ->where('revoked', false)
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
        $validated = $request->validate([
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
            'logo' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['required_with:allowed_origins', 'string', 'url'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', 'url'],
            'confidential' => ['sometimes', 'boolean'],
        ]);

        $client = DB::transaction(function () use ($request, $validated) {
            return Client::forceCreate([
                'owner_type' => User::class,
                'owner_id' => $request->user()->id,
                'name' => $validated['name'],
                'secret' => $request->boolean('confidential', true) ? Str::random(40) : null,
                'provider' => config('auth.guards.api.provider', 'users'),
                'logo' => $validated['logo'] ?? null,
                'description' => $validated['description'] ?? null,
                'allowed_origins' => isset($validated['allowed_origins'])
                    ? json_encode($validated['allowed_origins'], JSON_UNESCAPED_SLASHES)
                    : null,
                'redirect_uris' => $validated['redirect_uris'],
                'grant_types' => ['authorization_code', 'refresh_token'],
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

    private function presentClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'owner_type' => $client->owner_type,
            'owner_id' => $client->owner_id,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'provider' => $client->provider,
            'logo' => $client->logo,
            'description' => $client->description,
            'allowed_origins' => $this->normalizeList($client->allowed_origins),
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
