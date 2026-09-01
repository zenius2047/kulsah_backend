<?php

namespace App\Http\Controllers;

use App\Enums\OAuthClientType;
use App\Models\OAuthClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WebAuthController extends Controller
{
    public function create(Request $request)
    {
        return view('auth.login', $this->resolveOauthConsentContext($request));
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function resolveOauthConsentContext(Request $request): array
    {
        $context = [
            'oauthClient' => null,
            'oauthRedirectUri' => null,
        ];

        $intendedUrl = $request->session()->get('url.intended');

        if (! is_string($intendedUrl) || $intendedUrl === '') {
            return $context;
        }

        $parts = parse_url($intendedUrl);

        if ($parts === false) {
            return $context;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $clientId = $query['client_id'] ?? null;

        if (! is_string($clientId) && ! is_int($clientId)) {
            return $context;
        }

        $client = OAuthClient::query()->find($clientId);

        if (! $client) {
            return $context;
        }

        $clientType = $client->client_type;

        if (! $clientType instanceof OAuthClientType || ! $clientType->isMobile()) {
            return $context;
        }

        $redirectUri = $query['redirect_uri'] ?? ($client->redirect_uris[0] ?? null);

        return [
            'oauthClient' => $client,
            'oauthRedirectUri' => is_string($redirectUri) ? $redirectUri : null,
        ];
    }
}
