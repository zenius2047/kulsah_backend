<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\{AdminConsoleAudit, AdminConsoleRecord, Event, KulCoinTransaction, LiveSession, SignalReport, User, Video, WalletTransaction};
use App\Services\{AdminConsoleAccess, AdminConsoleMutations, AdminConsoleResources};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Storage};
use Illuminate\Validation\Rule;

class ConsoleController extends Controller
{
    public function __construct(private AdminConsoleAccess $access, private AdminConsoleResources $resources, private AdminConsoleMutations $mutations) {}

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 422, 'The email or password is incorrect.');
        abort_unless($user->activated, 422, 'Accept your administrator invitation and set a password before signing in.');
        $session = $this->access->session($user);
        $token = $user->createToken('admin-console', ['admin-console'], now()->addHours(8))->plainTextToken;
        return response()->json(['data' => $session, 'token' => $token]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        $this->access->role($user);
        abort_unless($user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken && $user->tokenCan('admin-console'), 403, 'A console session is required.');
        return $user;
    }

    private function authorizeRead(Request $request, string $resource): User
    {
        $permission = AdminConsoleResources::READ_PERMISSIONS[$resource] ?? abort(404);
        $user = $this->actor($request);
        $this->access->authorize($user, $permission);
        return $user;
    }

    public function session(Request $request) { return response()->json(['data' => $this->access->session($this->actor($request))]); }
    public function logout(Request $request) { $request->user()->currentAccessToken()?->delete(); return response()->json(['message' => 'Signed out.']); }

    public function acceptInvitation(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => 'required|string|min:12|confirmed']);
        DB::transaction(function () use ($data): void {
            $invitation = DB::table('admin_invitations')->where('token_hash', hash('sha256', $data['token']))
                ->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invitation, 422, 'This administrator invitation is invalid or has expired. Ask an administrator to send a new one.');
            $user = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->roles()->where('name', 'admin')->exists(), 422, 'This invitation no longer has administrator access.');
            $user->forceFill(['password' => Hash::make($data['password']), 'activated' => true, 'activated_at' => now()])->save();
            DB::table('admin_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            DB::table('admin_console_audits')->insert(['admin_id' => $user->id, 'action' => 'invitation.accepted', 'entity' => 'admins',
                'entity_id' => (string) $user->id, 'previous_value' => json_encode(['activated' => false]),
                'new_value' => json_encode(['activated' => true]), 'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 2000), 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['message' => 'Invitation accepted. You can now sign in to the admin console.']);
    }

    public function index(Request $request, string $resource)
    {
        $this->authorizeRead($request, $resource);
        $rows = $this->resources->rows($resource)->values();
        $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:200']);
        $page = $request->integer('page', 1); $size = $request->integer('per_page', 100);
        return response()->json(['data' => $rows->slice(($page - 1) * $size, $size)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $size)), 'total' => $rows->count()]]);
    }

    public function show(Request $request, string $resource, string $id)
    {
        $this->authorizeRead($request, $resource);
        $row = $this->resources->rows($resource)->firstWhere('id', $id);
        abort_unless($row, 404, 'This record no longer exists.');
        return response()->json(['data' => $row]);
    }

    public function store(Request $request, string $resource)
    {
        $user = $this->actor($request);
        return response()->json(['data' => $this->mutations->save($request, $user, $resource)], 201);
    }

    public function update(Request $request, string $resource, string $id)
    {
        $user = $this->actor($request);
        return response()->json(['data' => $this->mutations->save($request, $user, $resource, $id)]);
    }

    public function action(Request $request)
    {
        $user = $this->actor($request);
        $data = $request->validate(['resource' => ['required', Rule::in(array_keys(AdminConsoleResources::READ_PERMISSIONS))],
            'action' => 'required|string|max:60', 'ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|string|distinct', 'reason' => 'nullable|string|max:1000']);
        $this->mutations->act($request, $user, $data['resource'], $data['action'], $data['ids'], $data['reason'] ?? 'Administrator action');
        return response()->json(['message' => 'Action completed and recorded in the audit log.']);
    }

    public function range(Request $request): array
    {
        $request->validate(['range' => 'sometimes|in:today,yesterday,7d,30d,month,last-month,year,custom', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $end = now()->endOfDay();
        $start = match ($request->input('range', '7d')) {
            'today' => now()->startOfDay(), 'yesterday' => now()->subDay()->startOfDay(), '30d' => now()->subDays(29)->startOfDay(),
            'month' => now()->startOfMonth(), 'last-month' => now()->subMonthNoOverflow()->startOfMonth(), 'year' => now()->startOfYear(),
            'custom' => $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(13)->startOfDay(),
            default => now()->subDays(6)->startOfDay(),
        };
        if ($request->input('range') === 'yesterday') $end = now()->subDay()->endOfDay();
        if ($request->input('range') === 'last-month') $end = now()->subMonthNoOverflow()->endOfMonth();
        if ($request->input('range') === 'custom' && $request->filled('to')) $end = Carbon::parse($request->input('to'))->endOfDay();
        abort_if($start->diffInDays($end) > 366, 422, 'Select a date range of at most one year.');
        return [$start, $end];
    }

    public function chart(string $table, string $field, Carbon $start, Carbon $end, array $where = []): array
    {
        $query = DB::table($table)->whereBetween('created_at', [$start, $end]);
        foreach ($where as $column => $value) $query->where($column, $value);
        $select = $field === 'count' ? 'COUNT(*)' : 'SUM('.$field.')';
        $values = $query->selectRaw('DATE(created_at) AS day, '.$select.' AS aggregate')->groupByRaw('DATE(created_at)')->pluck('aggregate', 'day');
        $points = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) $points[] = ['label' => $day->format('d M'), 'value' => (float) ($values[$day->toDateString()] ?? 0)];
        return $points;
    }

    public function dashboard(Request $request)
    {
        $this->access->authorize($this->actor($request), 'analytics.view');
        [$start, $end] = $this->range($request);
        $periodDays = max(1, $start->diffInDays($end) + 1);
        $previousStart = $start->copy()->subDays($periodDays);
        $previousEnd = $start->copy()->subSecond();
        $creators = User::whereHas('roles', fn ($q) => $q->where('name', 'creator'));
        $metrics = [
            'Total users' => User::count(), 'Active users' => User::whereBetween('last_seen_at', [$start, $end])->count(),
            'New users' => User::whereBetween('created_at', [$start, $end])->count(), 'Total creators' => $creators->count(),
            'Verified creators' => (clone $creators)->where('verified', true)->count(), 'Active live streams' => LiveSession::where('status', 'live')->count(),
            'Total videos' => Video::count(), 'Total views' => Video::sum('views_count'), 'Total followers' => DB::table('user_follows')->count(),
            'Total subscribers' => DB::table('subscriptions')->where('status', 'active')->count(), 'Total events' => Event::count(),
            'Tickets sold' => DB::table('event_tickets')->count(),
            'Platform revenue' => WalletTransaction::where('status', 'completed')->whereBetween('created_at', [$start, $end])->sum('platform_fee_usd'),
            'Creator revenue' => WalletTransaction::where('status', 'completed')->whereBetween('created_at', [$start, $end])->sum('net_usd_amount'),
            'Pending withdrawals' => WalletTransaction::where('type', 'withdrawal')->where('status', 'pending')->count(),
            'Pending reports' => SignalReport::where('status', 'open')->count(),
        ];
        $currentPeriodMetrics = [
            'Total users' => User::whereBetween('created_at', [$start, $end])->count(),
            'Active users' => $metrics['Active users'],
            'New users' => $metrics['New users'],
            'Total creators' => (clone $creators)->whereBetween('created_at', [$start, $end])->count(),
            'Verified creators' => (clone $creators)->where('verified', true)->whereBetween('created_at', [$start, $end])->count(),
            'Total videos' => Video::whereBetween('created_at', [$start, $end])->count(),
            'Total followers' => DB::table('user_follows')->whereBetween('created_at', [$start, $end])->count(),
            'Total subscribers' => DB::table('subscriptions')->where('status', 'active')->whereBetween('created_at', [$start, $end])->count(),
            'Total events' => Event::whereBetween('created_at', [$start, $end])->count(),
            'Tickets sold' => DB::table('event_tickets')->whereBetween('created_at', [$start, $end])->count(),
            'Platform revenue' => $metrics['Platform revenue'],
            'Creator revenue' => $metrics['Creator revenue'],
            'Pending reports' => SignalReport::where('status', 'open')->whereBetween('created_at', [$start, $end])->count(),
        ];
        $previousMetrics = [
            'Total users' => User::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Active users' => User::whereBetween('last_seen_at', [$previousStart, $previousEnd])->count(),
            'New users' => User::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Total creators' => (clone $creators)->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Verified creators' => (clone $creators)->where('verified', true)->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Total videos' => Video::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Total followers' => DB::table('user_follows')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Total subscribers' => DB::table('subscriptions')->where('status', 'active')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Total events' => Event::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Tickets sold' => DB::table('event_tickets')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            'Platform revenue' => WalletTransaction::where('status', 'completed')->whereBetween('created_at', [$previousStart, $previousEnd])->sum('platform_fee_usd'),
            'Creator revenue' => WalletTransaction::where('status', 'completed')->whereBetween('created_at', [$previousStart, $previousEnd])->sum('net_usd_amount'),
            'Pending reports' => SignalReport::where('status', 'open')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
        ];
        $staticHints = [
            'Active live streams' => 'live right now',
            'Total views' => 'all-time total',
            'Pending withdrawals' => 'awaiting action',
        ];
        $kpis = collect($metrics)->map(function ($value, $label) use ($currentPeriodMetrics, $previousMetrics, $staticHints) {
            $hasComparison = array_key_exists($label, $currentPeriodMetrics);
            $previous = $hasComparison ? (float) $previousMetrics[$label] : null;
            $change = $hasComparison ? (float) $currentPeriodMetrics[$label] - $previous : null;
            if ($change === null) {
                $trend = 'flat';
                $delta = $staticHints[$label] ?? '';
            } elseif ($label === 'Pending reports') {
                $trend = $change > 0 ? 'down' : ($change < 0 ? 'up' : 'flat');
                $delta = ($change > 0 ? '+' : '').number_format($change, 0);
            } else {
                $percentage = $previous > 0 ? ($change / $previous) * 100 : ($change > 0 ? 100 : 0);
                $trend = $percentage > 0 ? 'up' : ($percentage < 0 ? 'down' : 'flat');
                $delta = sprintf('%+.1f%%', $percentage);
            }

            return ['label' => $label,
                'value' => (str_contains($label, 'revenue') ? 'GH'."\u{20B5}" : '').number_format((float) $value, str_contains($label, 'revenue') ? 2 : 0),
                'delta' => $delta, 'trend' => $trend];
        })->values();
        $total = max(1, User::count());
        $geo = User::selectRaw('country, COUNT(*) AS users')->groupBy('country')->orderByDesc('users')->get()->map(fn ($r) => ['country' => $r->country ?: 'Unknown', 'users' => (int) $r->users, 'share' => round($r->users / $total * 100, 1)]);
        return response()->json(['data' => ['kpis' => $kpis,
            'userGrowth' => $this->chart('users', 'count', $start, $end), 'revenue' => $this->chart('wallet_transactions', 'platform_fee_usd', $start, $end, ['status' => 'completed']),
            'engagement' => $this->chart('video_likes', 'count', $start, $end), 'contentPerformance' => $this->chart('videos', 'count', $start, $end),
            'geography' => $geo, 'liveNow' => LiveSession::with('creator')->where('status', 'live')->orderByDesc('current_viewers')->limit(6)->get()->map(fn ($s) => $this->resources->stream($s)),
            'queue' => $this->resources->rows('reports')->whereIn('status', ['Pending', 'Under Review'])->take(6)->values(),
        ]]);
    }

    public function revenue(Request $request)
    {
        $this->access->authorize($this->actor($request), 'finance.view');
        $tx = WalletTransaction::where('status', 'completed')->get();
        $gross = (float) $tx->sum('usd_amount'); $platform = (float) $tx->sum('platform_fee_usd');
        return response()->json(['data' => ['gross' => $gross, 'platform' => $platform, 'payouts' => $gross - $platform,
            'refunds' => (float) WalletTransaction::where('type', 'refund_reversal')->sum('usd_amount'),
            'byType' => $tx->groupBy('type')->map(fn ($items, $type) => ['type' => $this->resources->title($type), 'amount' => (float) $items->sum('usd_amount')])->values(),
            'monthly' => $this->chart('wallet_transactions', 'usd_amount', now()->subDays(29)->startOfDay(), now()->endOfDay(), ['status' => 'completed']),
        ]]);
    }

    public function coinOverview(Request $request)
    {
        $this->access->authorize($this->actor($request), 'kulcoin.view');
        $request->validate(['days' => 'sometimes|integer|min:1|max:366']);
        $start = now()->subDays($request->integer('days', 30) - 1)->startOfDay(); $end = now()->endOfDay();
        $p = KulCoinTransaction::where('type', 'purchase')->whereBetween('created_at', [$start, $end])->get();
        $g = KulCoinTransaction::where('type', 'gift')->where('status', 'completed')->whereBetween('created_at', [$start, $end])->get();
        $ok = $p->where('status', 'completed'); $coins = $g->sum('coin_amount'); $quantity = $g->sum(fn ($t) => (int) ($t->metadata['quantity'] ?? 1));
        return response()->json(['data' => ['purchased' => $ok->sum('net_coin_amount'), 'spent' => $coins,
            'held' => DB::table('kulcoin_wallets')->whereNotNull('user_id')->sum(DB::raw('available_balance_kc + bonus_balance_kc')),
            'revenue' => $ok->where('local_currency', 'GHS')->sum('local_amount'), 'giftCoins' => $coins, 'giftsSent' => $quantity,
            'creators' => $g->pluck('metadata.creator_id')->unique()->count(), 'avgGift' => $quantity ? round($coins / $quantity) : 0,
            'todayPurchases' => $p->filter(fn ($t) => $t->created_at->isToday())->count(), 'todayGifts' => $g->filter(fn ($t) => $t->created_at->isToday())->count(),
            'failed' => $p->where('status', 'failed')->count(), 'refunded' => $p->where('status', 'refunded')->count(),
            'purchasedSeries' => $this->chart('kulcoin_transactions', 'net_coin_amount', $start, $end, ['type' => 'purchase', 'status' => 'completed']),
            'spentSeries' => $this->chart('kulcoin_transactions', 'coin_amount', $start, $end, ['type' => 'gift', 'status' => 'completed']),
            'revenueSeries' => $this->chart('kulcoin_transactions', 'local_amount', $start, $end, ['type' => 'purchase', 'status' => 'completed', 'local_currency' => 'GHS']),
            'giftsSeries' => $this->chart('kulcoin_transactions', 'count', $start, $end, ['type' => 'gift', 'status' => 'completed']),
        ]]);
    }

    public function coinConfig(Request $request)
    {
        $u = $this->actor($request);
        $permissions = $this->access->permissions($this->access->role($u));
        abort_unless(in_array('kulcoin.view', $permissions, true) || in_array('gifts.view', $permissions, true), 403);
        return response()->json(['data' => ['coinValueGhs' => (float) config('kulcoin.coin_to_usd_rate'), 'creatorShare' => (int) config('kulcoin.creator_share_percent')]]);
    }

    public function search(Request $request)
    {
        $user = $this->actor($request); $term = mb_strtolower(trim((string) $request->input('q', '')));
        if (mb_strlen($term) < 2) return response()->json(['data' => []]);
        $groups = [];
        foreach (['users' => '/users/', 'creators' => '/creators/', 'videos' => '/content/videos/', 'events' => '/events/', 'reports' => '/moderation/reports/'] as $resource => $url) {
            if (!in_array(AdminConsoleResources::READ_PERMISSIONS[$resource], $this->access->permissions($this->access->role($user)), true)) continue;
            $items = $this->resources->rows($resource)->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', array_filter($row, 'is_scalar'))), $term))->take(5)->map(fn ($row) => [
                'id' => $row['id'], 'label' => $row['name'] ?? $row['title'] ?? $row['target'] ?? $row['id'],
                'meta' => $row['email'] ?? $row['creator'] ?? $row['organizer'] ?? '', 'to' => $url.$row['id'],
            ])->values();
            if ($items->isNotEmpty()) $groups[] = ['group' => ucfirst($resource), 'items' => $items];
        }
        return response()->json(['data' => $groups]);
    }

    public function related(Request $request, string $resource, string $id)
    {
        $user = $this->authorizeRead($request, $resource);
        abort_unless($this->resources->rows($resource)->firstWhere('id', $id), 404);
        $allowed = $this->access->permissions($this->access->role($user));
        $out = [];
        foreach (['users', 'creators', 'videos', 'streams', 'events', 'tickets', 'subscriptions', 'transactions', 'wallets', 'reports', 'audit'] as $related) {
            if (!in_array(AdminConsoleResources::READ_PERMISSIONS[$related], $allowed, true)) { $out[$related] = []; continue; }
            $rows = $this->resources->rows($related);
            $out[$related] = $rows->filter(function ($row) use ($resource, $related, $id) {
                if ($related === 'audit') return (string) $row['entityId'] === $id && $row['entity'] === $resource;
                if ($related === 'reports') {
                    if (in_array($resource, ['users', 'creators'], true)) return ($row['targetType'] === 'User' && $row['targetId'] === $id) || $row['reporterId'] === $id;
                    if ($resource === 'videos') return $row['targetType'] === 'Video' && $row['targetId'] === $id;
                    if ($resource === 'streams') return $row['targetType'] === 'Live Stream' && $row['targetId'] === $id;
                    return $resource === 'reports' && $row['id'] === $id;
                }
                if ($related === 'videos' && in_array($resource, ['users', 'creators'], true)) return ($row['creatorId'] ?? '') === $id;
                if ($related === 'tickets' && $resource === 'events') return ($row['eventId'] ?? '') === $id;
                return false;
            })->values();
        }
        $start = now()->subDays(13)->startOfDay(); $end = now()->endOfDay();
        $out['chart'] = match ($resource) {
            'users', 'creators' => $this->chart('videos', 'views_count', $start, $end, ['user_id' => $id]),
            'videos' => $this->chart('video_views', 'count', $start, $end, ['video_id' => $id]),
            'events' => $this->chart('event_tickets', 'count', $start, $end, ['event_id' => $id]),
            'streams' => $this->chart('live_viewer_sessions', 'count', $start, $end, ['live_session_id' => $id]),
            default => [],
        };
        return response()->json(['data' => $out]);
    }

    public function profile(Request $request)
    {
        $u = $this->actor($request);
        return response()->json(['data' => ['name' => $u->name, 'email' => $u->email, 'bio' => $u->bio ?? '', 'avatar' => $u->avatar, 'createdAt' => $this->resources->iso($u->created_at)]]);
    }

    public function updateProfile(Request $request)
    {
        $u = $this->actor($request); $data = $request->validate(['name' => 'required|string|max:255', 'bio' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $u, $data) { $before = $u->only(['name', 'bio']); $u->fill($data)->save(); $this->mutations->audit($request, $u, 'profile.update', 'users', (string) $u->id, $before, $data); });
        return $this->profile($request);
    }

    public function updateAvatar(Request $request)
    {
        $u = $this->actor($request);
        $request->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $oldPath = $u->getRawOriginal('avatar');
        $oldUrl = $u->avatar;
        $newPath = $request->file('avatar')->store('kulsah/admin-avatars', 's3');

        try {
            DB::transaction(function () use ($request, $u, $oldUrl, $newPath): void {
                $u->forceFill(['avatar' => $newPath])->save();
                $this->mutations->audit($request, $u, 'profile.avatar.update', 'users', (string) $u->id,
                    ['avatar' => $oldUrl], ['avatar' => $u->avatar]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('s3')->delete($newPath);
            throw $exception;
        }

        if ($oldPath && ! filter_var($oldPath, FILTER_VALIDATE_URL) && str_starts_with($oldPath, 'kulsah/')) {
            Storage::disk('s3')->delete($oldPath);
        }

        return $this->profile($request);
    }

    public function removeAvatar(Request $request)
    {
        $u = $this->actor($request);
        $oldPath = $u->getRawOriginal('avatar');
        $oldUrl = $u->avatar;

        DB::transaction(function () use ($request, $u, $oldUrl): void {
            $u->forceFill(['avatar' => null])->save();
            $this->mutations->audit($request, $u, 'profile.avatar.remove', 'users', (string) $u->id,
                ['avatar' => $oldUrl], ['avatar' => null]);
        });

        if ($oldPath && ! filter_var($oldPath, FILTER_VALIDATE_URL) && str_starts_with($oldPath, 'kulsah/')) {
            Storage::disk('s3')->delete($oldPath);
        }

        return $this->profile($request);
    }

    public function password(Request $request)
    {
        $u = $this->actor($request);
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:8|confirmed']);
        abort_unless(Hash::check($data['current_password'], $u->password), 422, 'Your current password is incorrect.');
        DB::transaction(function () use ($request, $u, $data) {
            $u->password = Hash::make($data['password']); $u->save();
            $u->tokens()->where('id', '!=', $u->currentAccessToken()->id)->delete();
            $this->mutations->audit($request, $u, 'password.change', 'users', (string) $u->id, [], ['other_sessions_revoked' => true]);
        });
        return response()->json(['message' => 'Password changed. Other sessions revoked.']);
    }

    public function preferences(Request $request)
    {
        $u = $this->actor($request);
        return response()->json(['data' => json_decode($u->console_preferences ?? '{}', true) ?: ['timezone' => 'Africa/Accra', 'language' => 'en', 'density' => 'compact']]);
    }

    public function savePreferences(Request $request)
    {
        $u = $this->actor($request);
        $data = $request->validate(['timezone' => 'required|timezone', 'language' => 'required|in:en', 'density' => 'required|in:compact,comfortable']);
        $prefs = json_decode($u->console_preferences ?? '{}', true) ?: [];
        $u->console_preferences = json_encode([...$prefs, ...$data]); $u->save();
        return response()->json(['data' => $data]);
    }

    public function alerts(Request $request)
    {
        $u = $this->actor($request);
        $permissions = $this->access->permissions($this->access->role($u));
        $prefs = json_decode($u->console_preferences ?? '{}', true) ?: [];
        $read = $prefs['readAlerts'] ?? [];
        $rows = [];
        foreach (['reports' => ['moderation', '/moderation/reports'], 'withdrawals' => ['finance', '/finance/withdrawals'], 'creators' => ['verification', '/verification']] as $resource => [$category, $url]) {
            if (!in_array(AdminConsoleResources::READ_PERMISSIONS[$resource], $permissions, true)) continue;
            $records = $this->resources->rows($resource)->filter(fn ($r) => $resource === 'creators' ? in_array($r['verification'], ['Pending', 'Under Review'], true) : in_array($r['status'], ['Pending', 'Under Review'], true))->take(20);
            foreach ($records as $record) {
                $id = $resource.':'.$record['id'];
                $rows[] = ['id' => $id, 'category' => $category, 'title' => $resource === 'creators' ? 'Creator verification pending' : ($resource === 'reports' ? 'Report awaiting review' : 'Withdrawal awaiting review'),
                    'body' => $record['name'] ?? $record['target'] ?? $record['creator'] ?? '', 'to' => $url,
                    'at' => Carbon::parse($record['createdAt'] ?? $record['requestedAt'] ?? $record['joinedAt'])->getTimestampMs(), 'read' => in_array($id, $read, true)];
            }
        }
        return response()->json(['data' => collect($rows)->sortByDesc('at')->values()]);
    }

    public function readAlerts(Request $request)
    {
        $u = $this->actor($request);
        $input = $request->validate(['id' => 'nullable|string|max:100']);
        $available = $this->alerts($request)->getData(true)['data'];
        $ids = array_column($available, 'id');
        if (isset($input['id'])) { abort_unless(in_array($input['id'], $ids, true), 404); $ids = [$input['id']]; }
        $prefs = json_decode($u->console_preferences ?? '{}', true) ?: [];
        $prefs['readAlerts'] = array_values(array_unique([...($prefs['readAlerts'] ?? []), ...$ids]));
        $u->console_preferences = json_encode($prefs); $u->save();
        return response()->json(['message' => 'Notifications updated.']);
    }
}
