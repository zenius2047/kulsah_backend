<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class PreferApiBearerToken
{
    public function handle(Request $request, Closure $next)
    {
        if ($bearer = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearer);
            abort_unless($token && $token->tokenable instanceof User && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Your access token has expired or was revoked.');
            if (str_starts_with($token->name, 'developer-key:')) {
                abort_unless($request->is('api/v1/developer/apps*', 'api/v1/developer/dashboard', 'api/v1/developer/logs*', 'api/v1/developer/tokens*', 'api/v1/developer/webhooks*'), 403, 'This API key is restricted to developer resource endpoints.');
                abort_unless($token->can($request->isMethod('GET') ? 'developer:read' : 'developer:write'), 403, 'This API key is read-only.');
            }
            if ($token->abilities === ['activate']) {
                abort_unless($request->is('api/v1/auth/activate', 'api/v1/auth/resend', 'api/v1/auth/me'), 403, 'Account activation is required.');
            }
            if ($token->abilities === ['refresh']) {
                abort_unless($request->is('api/v1/auth/refresh'), 403, 'Refresh tokens cannot access resources.');
            }
            $user = $token->tokenable->withAccessToken($token);
            Auth::guard('sanctum')->setUser($user);
            $request->setUserResolver(fn () => $user);
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
