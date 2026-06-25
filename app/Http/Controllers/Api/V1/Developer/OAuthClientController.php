<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'redirect_uris' => $client->redirect_uris,
                'grant_types' => $client->grant_types,
                'provider' => $client->provider,
                'revoked' => $client->revoked,
                'created_at' => $client->created_at,
                'updated_at' => $client->updated_at,
            ]);

        return response()->json([
            'data' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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
                'redirect_uris' => $validated['redirect_uris'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'revoked' => false,
            ]);
        });

        return response()->json([
            'message' => 'OAuth client created successfully.',
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'secret' => $client->plainSecret,
                'redirect_uris' => $client->redirect_uris,
                'grant_types' => $client->grant_types,
            ],
        ], 201);
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
}
