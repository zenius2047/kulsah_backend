<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class DeveloperWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->activated, 403, 'Activate your account before accessing the developer portal.');
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_if(in_array('activate', $token->abilities ?? [], true) || in_array('refresh', $token->abilities ?? [], true), 403, 'A full access token is required.');
            if (str_starts_with($token->name, 'developer-key:')) {
                abort_unless($request->is('api/v1/developer/apps*', 'api/v1/developer/dashboard', 'api/v1/developer/logs*', 'api/v1/developer/tokens*', 'api/v1/developer/webhooks*'), 403, 'This API key cannot manage account settings.');
                abort_unless($token->can($request->isMethod('GET') ? 'developer:read' : 'developer:write'), 403, 'This API key is read-only.');
            }
        }
        $ownerId = $request->header('X-Workspace-Id', $user->id);
        abort_unless(ctype_digit((string) $ownerId), 422, 'Invalid workspace.');
        $role = (string) $ownerId === (string) $user->id ? 'owner' : DB::table('developer_memberships')->where('owner_id', $ownerId)->where('user_id', $user->id)->value('role');
        abort_unless($role, 403, 'You do not have access to this workspace.');
        $request->attributes->set('workspace_owner_id', (int) $ownerId);
        $request->attributes->set('workspace_role', $role);
        // Personal account endpoints remain usable by viewers; workspace writes require a developer role.
        if (! $request->isMethod('GET') && $request->is('api/v1/developer/apps*', 'api/v1/developer/tokens*', 'api/v1/developer/webhooks*')) {
            abort_if($role === 'viewer', 403, 'Your workspace role is read-only.');
        }

        return $next($request);
    }
}
