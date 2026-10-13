<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Services\DeveloperTotp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class DeveloperAccountController extends Controller
{
    private function settings(Request $r): object
    {
        DB::table('developer_preferences')->insertOrIgnore(['user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('developer_preferences')->where('user_id', $r->user()->id)->first();
    }

    private function password(Request $r): void
    {
        $r->validate(['password' => ['required', 'string']]);
        abort_unless(Hash::check($r->input('password'), $r->user()->password), 422, 'Your password is incorrect.');
    }

    public function preferences(Request $r)
    {
        $s = $this->settings($r);

        return response()->json(['data' => ['product_notifications' => (bool) $s->product_notifications, 'security_alerts' => (bool) $s->security_alerts, 'two_factor_enabled' => $s->totp_confirmed_at !== null]]);
    }

    public function updatePreferences(Request $r)
    {
        $this->settings($r);
        $v = $r->validate(['product_notifications' => ['sometimes', 'boolean'], 'security_alerts' => ['sometimes', 'boolean']]);
        DB::table('developer_preferences')->where('user_id', $r->user()->id)->update([...$v, 'updated_at' => now()]);

        return $this->preferences($r);
    }

    public function profile(Request $r)
    {
        $v = $r->validate(['name' => ['required', 'string', 'min:2', 'max:255'], 'username' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($r->user()->id)], 'location' => ['nullable', 'string', 'max:255']]);
        $r->user()->fill($v)->save();

        return response()->json(['message' => 'Profile saved.']);
    }

    public function setupTwoFactor(Request $r, DeveloperTotp $totp)
    {
        $this->password($r);
        $s = $this->settings($r);
        abort_if($s->totp_confirmed_at !== null, 422, 'Two-factor authentication is already enabled.');
        $secret = $totp->secret();
        DB::table('developer_preferences')->where('user_id', $r->user()->id)->update(['totp_secret' => Crypt::encryptString($secret), 'totp_last_counter' => null, 'updated_at' => now()]);

        return response()->json(['data' => ['secret' => $secret, 'uri' => 'otpauth://totp/'.rawurlencode('Kulsah:'.($r->user()->email ?: $r->user()->phone)).'?secret='.$secret.'&issuer=Kulsah&algorithm=SHA1&digits=6&period=30']]);
    }

    public function confirmTwoFactor(Request $r, DeveloperTotp $totp)
    {
        $r->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        abort_if($this->settings($r)->totp_confirmed_at !== null, 422, 'Two-factor authentication is already enabled.');
        abort_unless($totp->verify($r->user()->id, $r->input('code'), true), 422, 'Invalid or already used authenticator code.');
        $codes = array_map(fn () => Str::lower(Str::random(12)), range(1, 8));
        DB::table('developer_preferences')->where('user_id', $r->user()->id)->update(['totp_confirmed_at' => now(), 'recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes)), 'updated_at' => now()]);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function disableTwoFactor(Request $r, DeveloperTotp $totp)
    {
        $this->password($r);
        $r->validate(['code' => ['required', 'string']]);
        abort_unless($totp->verify($r->user()->id, $r->input('code')), 422, 'Invalid or already used authenticator code.');
        DB::table('developer_preferences')->where('user_id', $r->user()->id)->update(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_counter' => null, 'recovery_codes' => null, 'updated_at' => now()]);

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    public function sessions(Request $r)
    {
        $current = $r->user()->currentAccessToken();
        $bearer = $r->bearerToken() ? PersonalAccessToken::findToken($r->bearerToken()) : null;
        $tokens = $r->user()->tokens()->where('name', 'not like', 'developer-key:%')->where('name', 'not like', '%refresh%')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']);
        $data = $tokens->map(fn ($t) => ['id' => 'token:'.$t->id, 'name' => explode('|', $t->name)[0], 'created_at' => $t->created_at, 'last_used_at' => $t->last_used_at, 'expires_at' => $t->expires_at, 'current' => (string) $t->id === (string) ($bearer?->id ?? ($current instanceof PersonalAccessToken ? $current->id : null))])->all();
        foreach (DB::table('sessions')->where('user_id', $r->user()->id)->where('last_activity', '>', time() - config('session.lifetime', 120) * 60)->get(['id', 'ip_address', 'user_agent', 'last_activity']) as $s) {
            $data[] = ['id' => 'session:'.$s->id, 'name' => Str::limit($s->user_agent ?? 'Browser session', 100), 'ip' => $s->ip_address, 'last_used_at' => date(DATE_ATOM, $s->last_activity), 'current' => $r->hasSession() && $r->session()->getId() === $s->id];
        }

        return response()->json(['data' => $data]);
    }

    public function revokeSession(Request $r, string $session)
    {
        if (str_starts_with($session, 'token:')) {
            $id = substr($session, 6);
            $t = $r->user()->tokens()->findOrFail($id);
            abort_if(str_starts_with($t->name, 'developer-key:'), 404);
            DB::transaction(function () use ($r, $t) {
                if (str_contains($t->name, '|') && str_ends_with($t->name, '-access')) {
                    $r->user()->tokens()->where('name', substr($t->name, 0, -7).'-refresh')->delete();
                }
                $t->delete();
            });
        } elseif (str_starts_with($session, 'session:')) {
            abort_unless(DB::table('sessions')->where('user_id', $r->user()->id)->where('id', substr($session, 8))->delete(), 404);
        } else {
            abort(404);
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeOtherSessions(Request $r)
    {
        $current = $r->bearerToken() ? PersonalAccessToken::findToken($r->bearerToken()) : null;
        $keep = $current ? [$current->id] : [];
        if ($current && str_contains($current->name, '|') && str_ends_with($current->name, '-access')) {
            $keep = [...$keep, ...$r->user()->tokens()->where('name', substr($current->name, 0, -7).'-refresh')->pluck('id')];
        }
        $r->user()->tokens()->where('name', 'not like', 'developer-key:%')->whereNotIn('id', $keep)->delete();
        DB::table('sessions')->where('user_id', $r->user()->id)->when($r->hasSession(), fn ($q) => $q->where('id', '!=', $r->session()->getId()))->delete();

        return response()->json(['message' => 'Other sessions revoked.']);
    }

    public function keys(Request $r)
    {
        return response()->json(['data' => $r->user()->tokens()->where('name', 'like', 'developer-key:%')->get(['id', 'name', 'abilities', 'created_at', 'last_used_at', 'expires_at'])]);
    }

    public function createKey(Request $r)
    {
        $this->password($r);
        $v = $r->validate(['name' => ['required', 'string', 'max:100'], 'access' => ['required', Rule::in(['read', 'write'])], 'days' => ['required', 'integer', 'min:1', 'max:365']]);
        $s = $this->settings($r);
        if ($s->totp_confirmed_at) {
            abort_unless(app(DeveloperTotp::class)->verify($r->user()->id, (string) $r->input('code')), 422, 'A valid authenticator code is required.');
        }
        $key = $r->user()->createToken('developer-key:'.$v['name'], $v['access'] === 'write' ? ['developer:read', 'developer:write'] : ['developer:read'], now()->addDays($v['days']));

        return response()->json(['data' => ['id' => $key->accessToken->id, 'token' => $key->plainTextToken]], 201);
    }

    public function revokeKey(Request $r, int $key)
    {
        $r->user()->tokens()->where('name', 'like', 'developer-key:%')->findOrFail($key)->delete();

        return response()->json(['message' => 'API key revoked.']);
    }

    public function notifications(Request $r)
    {
        return response()->json(['data' => DB::table('developer_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->limit(100)->get(), 'unread' => DB::table('developer_notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->count()]);
    }

    public function readNotifications(Request $r)
    {
        DB::table('developer_notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->when($r->filled('id'),fn ($q) => $q->where('id',$r->integer('id')))->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
