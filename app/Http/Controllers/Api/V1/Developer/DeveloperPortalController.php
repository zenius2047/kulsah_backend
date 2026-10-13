<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverDeveloperWebhook;
use App\Models\User;
use App\Services\DeveloperWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Passport\Passport;

class DeveloperPortalController extends Controller
{
    private function owner(Request $r): int
    {
        return $r->attributes->get('workspace_owner_id', $r->user()->id);
    }

    private function admin(Request $r): void
    {
        abort_unless(in_array($r->attributes->get('workspace_role'), ['owner', 'admin']), 403, 'Only workspace owners and admins can manage members.');
    }

    private function clients(Request $r)
    {
        return DB::table('oauth_clients')->where('owner_type', User::class)->where('owner_id', $this->owner($r));
    }

    private function tokenQuery(Request $r)
    {
        return DB::table('oauth_access_tokens as t')->join('oauth_clients as c', 't.client_id', '=', 'c.id')->leftJoin('users as u', 't.user_id', '=', 'u.id')->where('c.owner_type', User::class)->where('c.owner_id', $this->owner($r));
    }

    private function logsQuery(Request $r)
    {
        $v = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'client_id' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1']]);
        if ($r->filled('client_id')) {
            abort_unless($this->clients($r)->where('id', $v['client_id'])->exists(), 404);
        }

        return DB::table('developer_request_logs')->where('owner_id', $this->owner($r))->when($r->filled('client_id'), fn ($q) => $q->where('client_id', $v['client_id']))->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('path', 'like', '%'.$v['search'].'%')->orWhere('ip', 'like', '%'.$v['search'].'%')->orWhere('event', 'like', '%'.$v['search'].'%')));
    }

    public function workspaces(Request $r)
    {
        $mine = ['id' => $r->user()->id, 'name' => $r->user()->name.' workspace', 'role' => 'owner'];
        $shared = DB::table('developer_memberships as m')->join('users as u', 'u.id', '=', 'm.owner_id')->where('m.user_id', $r->user()->id)->get(['u.id', 'u.name', 'm.role'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name.' workspace', 'role' => $s->role]);

        return response()->json(['data' => [$mine, ...$shared], 'current_id' => $this->owner($r), 'role' => $r->attributes->get('workspace_role')]);
    }

    public function dashboard(Request $r)
    {
        $logs = DB::table('developer_request_logs')->where('owner_id', $this->owner($r));
        $start = now()->startOfDay()->subDays(6);
        $daily = (clone $logs)->where('created_at', '>=', $start)->selectRaw('DATE(created_at) as day, COUNT(*) as requests, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as failures')->groupByRaw('DATE(created_at)')->get()->keyBy('day');
        $issued = $this->tokenQuery($r)->where('t.created_at', '>=', $start)->selectRaw('DATE(t.created_at) as day, COUNT(*) as tokens')->groupByRaw('DATE(t.created_at)')->get()->keyBy('day');
        $activity = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i);
            $key = $d->format('Y-m-d');
            $activity[] = ['day' => $d->format('D'), 'date' => $key, 'requests' => (int) ($daily[$key]->requests ?? 0), 'failures' => (int) ($daily[$key]->failures ?? 0), 'tokens' => (int) ($issued[$key]->tokens ?? 0)];
        }

        return response()->json(['data' => [
            'applications' => $this->clients($r)->where('revoked', false)->count(),
            'active_tokens' => $this->tokenQuery($r)->where('t.revoked', false)->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))->count(),
            'requests_today' => (clone $logs)->where('created_at', '>=', now()->startOfDay())->count(),
            'failed_requests_today' => (clone $logs)->where('created_at', '>=', now()->startOfDay())->where('status', '>=', 400)->count(),
            'activity' => $activity, 'recent' => (clone $logs)->orderByDesc('id')->limit(8)->get(),
            'regions' => (clone $logs)->where('created_at', '>=', now()->subDay())->whereNotNull('country_code')->selectRaw('country_code, COUNT(*) as requests')->groupBy('country_code')->get(),
            'recording_since' => DB::table('developer_request_logs')->where('owner_id', $this->owner($r))->min('created_at'),
        ]]);
    }

    public function tokens(Request $r)
    {
        $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->tokenQuery($r)->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('c.name', 'like', '%'.$r->input('search').'%')->orWhere('u.email', 'like', '%'.$r->input('search').'%')))->orderByDesc('t.created_at')->select(['t.id', 't.client_id', 'c.name as application', 'u.name as user_name', 'u.email', 't.scopes', 't.revoked', 't.created_at', 't.expires_at'])->paginate(25));
    }

    public function revokeToken(Request $r, string $token)
    {
        abort_unless($this->tokenQuery($r)->where('t.id', $token)->exists(), 404);
        DB::transaction(function () use ($token) {
            Passport::token()->whereKey($token)->firstOrFail()->revoke();
            Passport::refreshToken()->where('access_token_id', $token)->update(['revoked' => true]);
        });

        return response()->json(['message' => 'Access and refresh tokens revoked.']);
    }

    public function logs(Request $r)
    {
        return response()->json($this->logsQuery($r)->orderByDesc('id')->paginate(25));
    }

    public function log(Request $r, int $log)
    {
        return response()->json(['data' => $this->logsQuery($r)->where('id', $log)->firstOrFail()]);
    }

    public function authorizations(Request $r)
    {
        return response()->json(['data' => DB::table('oauth_access_tokens as t')->join('oauth_clients as c', 'c.id', '=', 't.client_id')->where('t.user_id', $r->user()->id)->where('t.revoked', false)->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))->orderByDesc('t.created_at')->get(['t.id', 't.client_id', 'c.name', 'c.logo', 't.scopes', 't.created_at', 't.expires_at'])]);
    }

    public function revokeAuthorization(Request $r, string $token)
    {
        $grant = DB::table('oauth_access_tokens')->where('id', $token)->where('user_id', $r->user()->id)->firstOrFail();
        $ids = DB::table('oauth_access_tokens')->where('user_id', $r->user()->id)->where('client_id', $grant->client_id)->pluck('id');
        DB::transaction(function () use ($ids) {
            foreach ($ids as $id) {
                Passport::token()->whereKey($id)->firstOrFail()->revoke();
                Passport::refreshToken()->where('access_token_id', $id)->update(['revoked' => true]);
            }
        });

        return response()->json(['message' => 'Application access revoked.']);
    }

    public function team(Request $r)
    {
        $owner = User::findOrFail($this->owner($r));
        $members = DB::table('developer_memberships as m')->join('users as u', 'm.user_id', '=', 'u.id')->where('m.owner_id', $owner->id)->get(['m.id', 'u.id as user_id', 'u.name', 'u.email', 'm.role', 'm.created_at']);
        $invites = in_array($r->attributes->get('workspace_role'), ['owner', 'admin']) ? DB::table('developer_invitations')->where('owner_id', $owner->id)->whereNull('accepted_at')->where('expires_at', '>', now())->get(['id', 'email', 'role', 'expires_at']) : [];

        return response()->json(['data' => ['owner' => ['user_id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'role' => 'owner'], 'members' => $members, 'invitations' => $invites, 'role' => $r->attributes->get('workspace_role')]]);
    }

    public function invite(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', Rule::in(['admin', 'developer', 'viewer'])]]);
        abort_if($v['role'] === 'admin' && $r->attributes->get('workspace_role') !== 'owner', 403, 'Only the owner can invite administrators.');
        $email = strtolower($v['email']);
        $owner = User::findOrFail($this->owner($r));
        abort_if(strtolower($owner->email ?? '') === $email, 422, 'This user already owns the workspace.');
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            abort_if(DB::table('developer_memberships')->where('owner_id', $owner->id)->where('user_id', $existing->id)->exists(), 422, 'This user is already a member.');
        }
        DB::table('developer_invitations')->where('owner_id', $owner->id)->where('email', $email)->whereNull('accepted_at')->delete();
        $token = Str::random(64);
        $id = DB::table('developer_invitations')->insertGetId(['owner_id' => $owner->id, 'email' => $email, 'role' => $v['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id, 'token' => $token, 'expires_at' => now()->addDays(7)->toIso8601String()]], 201);
    }

    public function acceptInvitation(Request $r)
    {
        $v = $r->validate(['token' => ['required', 'string', 'size:64']]);
        $owner = DB::transaction(function () use ($r, $v) {
            $invite = DB::table('developer_invitations')->where('token_hash', hash('sha256', $v['token']))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invite, 404, 'Invitation expired or already used.');
            abort_unless(strtolower($invite->email) === strtolower($r->user()->email ?? ''), 403, 'Sign in with the email address invited to this workspace.');
            DB::table('developer_memberships')->updateOrInsert(['owner_id' => $invite->owner_id, 'user_id' => $r->user()->id], ['role' => $invite->role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('developer_invitations')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);

            return $invite->owner_id;
        });

        return response()->json(['data' => ['owner_id' => $owner], 'message' => 'Invitation accepted.']);
    }

    public function cancelInvitation(Request $r, int $invitation)
    {
        $this->admin($r);
        abort_unless(DB::table('developer_invitations')->where('owner_id', $this->owner($r))->where('id', $invitation)->delete(), 404);

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    public function updateMember(Request $r, int $member)
    {
        $this->admin($r);
        $v = $r->validate(['role' => ['required', Rule::in(['admin', 'developer', 'viewer'])]]);
        $m = DB::table('developer_memberships')->where('owner_id', $this->owner($r))->where('id', $member)->firstOrFail();
        abort_if($r->attributes->get('workspace_role') !== 'owner' && ($m->role === 'admin' || $v['role'] === 'admin'), 403, 'Only the owner can change administrator permissions.');
        DB::table('developer_memberships')->where('id', $m->id)->update(['role' => $v['role'], 'updated_at' => now()]);

        return response()->json(['message' => 'Member role updated.']);
    }

    public function removeMember(Request $r, int $member)
    {
        $this->admin($r);
        $m = DB::table('developer_memberships')->where('owner_id', $this->owner($r))->where('id', $member)->firstOrFail();
        abort_if($r->attributes->get('workspace_role') !== 'owner' && $m->role === 'admin', 403, 'Only the owner can remove administrators.');
        DB::table('developer_memberships')->where('id', $m->id)->delete();

        return response()->json(['message' => 'Member removed.']);
    }

    public function billing(Request $r)
    {
        return response()->json(['data' => ['plan' => config('developer.plan_name'), 'billing_enabled' => config('developer.billing_enabled'), 'applications' => $this->clients($r)->where('revoked', false)->count(), 'requests_this_month' => DB::table('developer_request_logs')->where('owner_id', $this->owner($r))->where('created_at', '>=', now()->startOfMonth())->count(), 'invoices' => []]]);
    }

    private function webhook(Request $r, int $id)
    {
        return DB::table('developer_webhooks')->where('owner_id', $this->owner($r))->where('id', $id)->firstOrFail();
    }

    public function webhooks(Request $r)
    {
        $hooks = DB::table('developer_webhooks')->where('owner_id', $this->owner($r))->orderByDesc('id')->get(['id', 'name', 'url', 'events', 'enabled', 'created_at'])->map(function ($h) {
            $h->events = json_decode($h->events, true);
            $h->enabled = (bool) $h->enabled;

            return $h;
        });

        return response()->json(['data' => $hooks, 'events' => config('developer.webhook_events')]);
    }

    public function createWebhook(Request $r, DeveloperWebhookUrl $url)
    {
        $v = $r->validate(['name' => ['required', 'string', 'max:255'], 'url' => ['required', 'url', 'max:2048'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['required', Rule::in(config('developer.webhook_events'))]]);
        $url->resolve($v['url']);
        $secret = Str::random(48);
        $id = DB::table('developer_webhooks')->insertGetId(['owner_id' => $this->owner($r), 'name' => $v['name'], 'url' => $v['url'], 'events' => json_encode(array_values(array_unique($v['events']))), 'secret' => Crypt::encryptString($secret), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id, 'secret' => $secret]], 201);
    }

    public function updateWebhook(Request $r, int $webhook, DeveloperWebhookUrl $url)
    {
        $this->webhook($r, $webhook);
        $v = $r->validate(['enabled' => ['sometimes', 'boolean'], 'name' => ['sometimes', 'string', 'max:255'], 'url' => ['sometimes', 'url', 'max:2048'], 'events' => ['sometimes', 'array', 'min:1'], 'events.*' => ['required', Rule::in(config('developer.webhook_events'))]]);
        if (isset($v['url'])) {
            $url->resolve($v['url']);
        }if (isset($v['events'])) {
            $v['events'] = json_encode($v['events']);
        }DB::table('developer_webhooks')->where('id', $webhook)->update([...$v, 'updated_at' => now()]);

        return response()->json(['message' => 'Webhook updated.']);
    }

    public function deleteWebhook(Request $r, int $webhook)
    {
        $this->webhook($r, $webhook);
        DB::table('developer_webhooks')->where('id', $webhook)->delete();

        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function deliveries(Request $r, int $webhook)
    {
        $this->webhook($r, $webhook);

        return response()->json(['data' => DB::table('developer_webhook_deliveries')->where('webhook_id', $webhook)->orderByDesc('id')->limit(50)->get(['id', 'event', 'status', 'response_status', 'error', 'attempts', 'created_at', 'delivered_at'])]);
    }

    public function testWebhook(Request $r,int $webhook)
    {
        $this->webhook($r,$webhook);
        $id = DB::table('developer_webhook_deliveries')->insertGetId(['webhook_id' => $webhook, 'event' => 'webhook.test', 'payload' => json_encode(['event' => 'webhook.test', 'timestamp' => now()->toIso8601String()]), 'created_at' => now(), 'updated_at' => now()]);
        DeliverDeveloperWebhook::dispatch($id);

        return response()->json(['data' => ['id' => $id], 'message' => 'Test delivery queued.'],202);
    }
}
