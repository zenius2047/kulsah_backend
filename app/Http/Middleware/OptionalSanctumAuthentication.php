<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalSanctumAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();
        $user = Auth::guard('sanctum')->user();

        if ($bearerToken !== null && ! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($user) {
            Auth::shouldUse('sanctum');
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
