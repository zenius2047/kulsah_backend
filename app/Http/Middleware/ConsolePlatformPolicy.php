<?php

namespace App\Http\Middleware;

use App\Models\AdminConsoleRecord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ConsolePlatformPolicy
{
    public function handle(Request $request, Closure $next)
    {
        if (!Schema::hasTable('admin_console_records')) return $next($request);
        $user = auth('sanctum')->user();
        if ($user) {
            abort_unless($user->console_status === 'Active', 403, 'This account is suspended.');
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->abilities === ['admin-console']) {
                abort_unless($request->is('api/v1/admin/*'), 403, 'Console sessions can only access console endpoints.');
            }
        }
        $settings = AdminConsoleRecord::where('resource', 'settings')->first()?->payload;
        if ($settings) {
            config(['app.name' => $settings['platformName'], 'console.support_email' => $settings['supportEmail']]);
            if ($request->is('api/v1/auth/register')) abort_unless($settings['registrationsEnabled'], 503, 'Registration is temporarily disabled.');
            if ($request->is('api/v1/creator/live') && $request->isMethod('post')) abort_unless($settings['liveEnabled'], 503, 'Live streaming is temporarily disabled.');
        }
        return $next($request);
    }
}
