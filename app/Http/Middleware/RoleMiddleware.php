<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // 1. Check authentication
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated user'
            ], 401);
        }

        // 2. If no roles provided, allow access
        if (empty($roles)) {
            return $next($request);
        }

        // 3. Get user roles from DB (many-to-many)
        $userRoles = $user->roles()
            ->pluck('name')
            ->toArray();

        // 4. Normalize allowed roles (supports: role:creator|fan OR role:creator,fan)
        $allowed = [];

        foreach ($roles as $roleGroup) {
            if (!is_string($roleGroup)) {
                continue;
            }

            foreach (explode('|', $roleGroup) as $role) {
                $role = trim($role);

                if (!empty($role)) {
                    $allowed[] = $role;
                }
            }
        }

        // 5. Check if user has ANY allowed role
        $hasRole = count(array_intersect($userRoles, $allowed)) > 0;

        if (!$hasRole) {
            return response()->json([
                'message' => 'Unauthorized user role',
                'allowed_roles' => $allowed,
                'user_role' => $userRoles,
            ], 403);
        }

        // 6. Continue request
        return $next($request);
    }
}