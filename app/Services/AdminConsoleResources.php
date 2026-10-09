<?php

namespace App\Services;

use App\Models\{AdminConsoleAudit, AdminConsoleRecord, Challenge, CommunityPost, Event, EventTicket, KulCoinGift, KulCoinLedgerEntry, KulCoinPackage, KulCoinTransaction, KulCoinWallet, LiveSession, SignalReport, Subscription, User, Wallet, WalletTransaction};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminConsoleResources
{
    public function __construct(private readonly KulCoinConversionService $conversion) {}

    public const READ_PERMISSIONS = [
        'users' => 'users.view', 'creators' => 'creators.view', 'videos' => 'content.view', 'community-posts' => 'content.view',
        'streams' => 'content.view', 'challenges' => 'content.view', 'featured' => 'content.view',
        'events' => 'events.view', 'tickets' => 'tickets.view', 'subscriptions' => 'subscriptions.view',
        'transactions' => 'finance.view', 'withdrawals' => 'finance.payout', 'wallets' => 'finance.view',
        'reports' => 'moderation.view', 'notifications' => 'notifications.view', 'cms' => 'cms.view',
        'audit' => 'audit.view', 'admins' => 'admins.view', 'roles' => 'admins.view',
        'packages' => 'kulcoin.view', 'purchases' => 'kulcoin.view', 'ledger' => 'kulcoin.view',
        'balances' => 'kulcoin.view', 'adjustments' => 'kulcoin.adjust', 'gifts' => 'gifts.view',
        'gift-transactions' => 'gifts.view', 'gift-earnings' => 'gifts.view', 'risk-alerts' => 'gifts.view',
        'settings' => 'settings.view',
    ];

    public function rows(string $resource): Collection
    {
        return match ($resource) {
            'users' => User::with('roles')->withCount(['followers', 'videos'])->get()->map(fn ($u) => $this->user($u)),
            'creators' => User::whereHas('roles', fn ($q) => $q->where('name', 'creator'))->withCount(['followers', 'videos', 'events', 'subscribers'])->with('wallet')->get()->map(fn ($u) => $this->creator($u)),
            'videos' => \App\Models\Video::with('user')->get()->map(fn ($v) => $this->video($v)),
            'community-posts' => CommunityPost::with(['user', 'media'])->withCount(['likes', 'comments', 'shares'])->latest()->get()->map(fn ($post) => [
                'id' => (string) $post->id, 'type' => (string) $post->type, 'content' => (string) ($post->content ?? ''),
                'creator' => $post->user?->name ?? 'Deleted user', 'audience' => $this->title((string) ($post->audience ?? 'public')),
                'status' => $this->title((string) ($post->status ?? 'published')),
                'media' => $post->media->map(fn ($media) => $media->cloudinary_url ?: $media->source_url)->filter()->values()->all(),
                'hashtags' => is_array($post->hashtags) ? $post->hashtags : [], 'location' => (string) ($post->location_name ?? ''),
                'views' => (int) ($post->views_count ?? $post->views_count_count ?? 0), 'likes' => (int) $post->likes_count,
                'comments' => (int) $post->comments_count, 'shares' => (int) $post->shares_count,
                'reports' => SignalReport::where('reportable_type', CommunityPost::class)->where('reportable_id', $post->id)->count(),
                'publishedAt' => $this->iso($post->published_at), 'createdAt' => $this->iso($post->created_at),
            ]),
            'streams' => LiveSession::with('creator')->get()->map(fn ($s) => $this->stream($s)),
            'challenges' => Challenge::with('creator')->withCount(['entries', 'participants'])->get()->map(fn ($c) => [
                'id' => (string) $c->id, 'title' => $c->title, 'creator' => $c->creator?->name ?? 'Deleted user',
                'participants' => $c->participants_count, 'videos' => $c->entries_count,
                'views' => (int) ($c->metadata['views'] ?? 0), 'likes' => (int) ($c->metadata['likes'] ?? 0),
                'startDate' => $this->iso($c->submission_starts_at), 'endDate' => $this->iso($c->submission_ends_at),
                'status' => $this->title($c->status),
            ]),
            'events' => Event::with('creator')->withSum(['purchases' => fn ($q) => $q->whereIn('status', ['paid', 'completed'])->where('currency', 'GHS')], 'total_amount')->get()->map(fn ($e) => $this->event($e)),
            'tickets' => EventTicket::with(['event', 'buyer', 'purchase'])->get()->map(fn ($t) => [
                'id' => (string) $t->id, 'eventId' => (string) $t->event_id, 'eventName' => $t->event?->title ?? 'Deleted event',
                'customer' => $t->buyer?->name ?? 'Deleted user', 'type' => $t->purchase?->ticket_type_name ?? 'Regular',
                'price' => $t->purchase?->currency === 'GHS' ? (float) $t->purchase->unit_price : 0,
                'paymentStatus' => $t->purchase?->status === 'completed' ? 'Paid' : $this->title($t->purchase?->status ?? 'pending'),
                'ticketStatus' => $this->title($t->status), 'purchasedAt' => $this->iso($t->created_at), 'checkedIn' => $t->verified_at !== null,
            ]),
            'subscriptions' => Subscription::with(['subscriber', 'creator', 'plan'])->get()->map(fn ($s) => [
                'id' => (string) $s->id, 'subscriber' => $s->subscriber?->name ?? 'Deleted user', 'creator' => $s->creator?->name ?? 'Deleted user',
                'plan' => $s->plan?->name ?? 'Unavailable plan', 'price' => $s->plan?->currency === 'GHS' ? (float) $s->plan->price : 0,
                'startDate' => $this->iso($s->starts_at), 'renewalDate' => $this->iso($s->expires_at), 'status' => $this->title($s->status),
                'revenue' => (float) WalletTransaction::where('user_id', $s->subscriber_id)->where('type', 'subscription')->where('status', 'completed')->where('metadata->subscription_id', $s->id)->sum('usd_amount'),
            ]),
            'transactions' => WalletTransaction::with(['wallet.user', 'counterpartyWallet.user'])->get()->map(fn ($t) => $this->transaction($t)),
            'withdrawals' => WalletTransaction::where('type', 'withdrawal')->with(['wallet.user', 'actor'])->get()->map(fn ($t) => [
                'id' => (string) $t->id, 'creator' => $t->wallet?->user?->name ?? 'Deleted user', 'amount' => (float) $t->usd_amount,
                'method' => $t->metadata['method'] ?? 'Bank Transfer', 'accountRef' => '•••• '.substr((string) ($t->metadata['account_ref'] ?? ''), -4),
                'requestedAt' => $this->iso($t->created_at), 'status' => $this->title($t->status), 'reviewer' => $t->actor?->name,
            ]),
            'wallets' => Wallet::whereNotNull('user_id')->where('base_currency', 'GHS')->with('user')->get()->map(fn ($w) => [
                'id' => (string) $w->id, 'owner' => $w->user?->name ?? $w->account_name,
                'balance' => (float) $w->available_balance_usd + (float) $w->pending_balance_usd + (float) $w->held_balance_usd,
                'available' => (float) $w->available_balance_usd, 'pending' => (float) $w->pending_balance_usd,
                'currency' => 'GHS', 'status' => $this->title($w->status),
            ]),
            'reports' => SignalReport::with(['reporter', 'reportable'])->get()->map(fn ($r) => [
                'id' => (string) $r->id, 'targetId' => (string) $r->reportable_id, 'reporterId' => (string) $r->reporter_id, 'target' => $r->reportable?->title ?? $r->reportable?->name ?? (string) $r->reportable_id,
                'targetType' => match (class_basename($r->reportable_type)) { 'User' => 'User', 'Video' => 'Video', 'LiveSession' => 'Live Stream', default => 'Comment' },
                'creator' => $r->reportable instanceof User ? $r->reportable->name : ($r->reportable?->user?->name ?? $r->reportable?->creator?->name ?? 'Unknown'),
                'reporter' => $r->reporter?->name ?? 'Deleted user', 'reason' => $this->title($r->category), 'priority' => 'Medium',
                'status' => $r->status === 'open' ? 'Pending' : $this->title($r->status), 'moderator' => null,
                'createdAt' => $this->iso($r->created_at), 'notes' => $r->reason ?? '',
            ]),
            'admins' => User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get()->map(fn ($u) => [
                'id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->admin_console_role ?: 'Super Admin',
                'status' => $u->admin_console_disabled ? 'Disabled' : (! $u->activated ? 'Invited' : 'Active'), 'lastLogin' => $this->iso($u->last_seen_at), 'createdAt' => $this->iso($u->created_at),
            ]),
            'roles' => collect(AdminConsoleAccess::ROLES)->map(fn ($role) => ['id' => $role, 'name' => $role,
                'description' => 'Server-enforced '.$role.' permissions.', 'permissions' => app(AdminConsoleAccess::class)->permissions($role),
                'adminCount' => User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->where(fn ($q) => $role === 'Super Admin' ? $q->where('admin_console_role', $role)->orWhereNull('admin_console_role') : $q->where('admin_console_role', $role))->count(),
            ]),
            'audit' => AdminConsoleAudit::latest()->get()->map(fn ($a) => ['id' => (string) $a->id,
                'admin' => User::find($a->admin_id)?->name ?? 'Deleted administrator', 'action' => $a->action,
                'entity' => $a->entity, 'entityId' => $a->entity_id ?? '', 'previousValue' => json_encode($a->previous_value),
                'newValue' => json_encode($a->new_value), 'ip' => $a->ip ?? '', 'userAgent' => $a->user_agent ?? '', 'timestamp' => $this->iso($a->created_at),
            ]),
            'packages' => KulCoinPackage::withCount([])->get()->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name,
                'coins' => $p->coin_amount, 'bonus' => $p->bonus_coin_amount, 'price' => $p->currency_code === 'GHS' ? (float) $p->usd_price : 0,
                'currency' => $p->currency_code, 'discount' => (float) ($p->metadata['discount'] ?? 0), 'order' => $p->sort_order,
                'status' => $p->metadata['console_status'] ?? ($p->is_active ? 'Active' : 'Inactive'),
                'purchases' => KulCoinTransaction::where('package_id', $p->id)->where('status', 'completed')->count(),
                'createdAt' => $this->iso($p->created_at), 'updatedAt' => $this->iso($p->updated_at),
            ]),
            'purchases' => KulCoinTransaction::where('type', 'purchase')->with(['wallet.user', 'package'])->get()->map(fn ($p) => [
                'id' => (string) $p->id, 'user' => $p->wallet?->user?->name ?? 'Deleted user', 'country' => $p->wallet?->user?->country ?? '',
                'packageName' => $p->package?->name ?? 'Deleted package', 'coins' => $p->coin_amount, 'bonus' => $p->bonus_coin_amount,
                'amount' => $p->local_currency === 'GHS' ? (float) $p->local_amount : 0, 'currency' => $p->local_currency,
                'provider' => $p->metadata['provider'] ?? 'Paystack', 'reference' => $p->reference,
                'status' => $p->status === 'completed' ? 'Successful' : $this->title($p->status), 'date' => $this->iso($p->created_at),
            ]),
            'ledger' => KulCoinLedgerEntry::whereHas('wallet', fn ($q) => $q->whereNotNull('user_id'))->with(['wallet.user', 'kulCoinTransaction'])->get()->map(fn ($l) => [
                'id' => (string) $l->id, 'user' => $l->wallet?->user?->name ?? 'Deleted user', 'type' => $this->title($l->kulCoinTransaction?->type ?? 'Adjustment'),
                'direction' => $this->title($l->entry_type), 'coins' => $l->amount_kc,
                'balanceAfter' => $l->running_balance_kc, 'reference' => $l->kulCoinTransaction?->reference ?? '', 'date' => $this->iso($l->created_at),
            ]),
            'balances' => KulCoinWallet::whereNotNull('user_id')->with('user')->get()->map(fn ($w) => [
                'id' => (string) $w->id, 'userId' => (string) $w->user_id, 'user' => $w->user?->name ?? 'Deleted user', 'country' => $w->user?->country ?? '',
                'balance' => $w->available_balance_kc + $w->bonus_balance_kc,
                'purchased' => (int) $w->transactions()->where('type', 'purchase')->where('status', 'completed')->sum('net_coin_amount'),
                'spent' => (int) $w->transactions()->whereIn('type', ['gift', 'vote'])->where('status', 'completed')->sum('net_coin_amount'),
                'promotional' => $w->bonus_balance_kc, 'lastActivity' => $this->iso($w->last_ledger_at ?? $w->created_at), 'status' => $this->title($w->status), 'risk' => 'Low',
            ]),
            'gifts' => KulCoinGift::get()->map(fn ($g) => ['id' => (string) $g->id, 'name' => $g->name,
                'emoji' => $g->metadata['emoji'] ?? '🎁', 'image' => $g->icon_url, 'description' => $g->metadata['description'] ?? '',
                'category' => $g->category ?: 'Basic', 'rarity' => $g->metadata['rarity'] ?? 'Common', 'coinPrice' => $g->coin_cost,
                'creatorShare' => (int) ($g->metadata['creatorShare'] ?? config('kulcoin.creator_share_percent')), 'animation' => $g->metadata['animation'] ?? ($g->animation_url ? 'Animated' : 'Static'),
                'contexts' => $g->metadata['contexts'] ?? ['Live Stream', 'Video'],
                'sent' => (int) KulCoinTransaction::where('gift_id', $g->id)->where('status', 'completed')->get()->sum(fn ($t) => (int) ($t->metadata['quantity'] ?? 1)),
                'status' => $g->metadata['console_status'] ?? ($g->is_active ? 'Active' : 'Inactive'),
            ]),
            'gift-transactions' => KulCoinTransaction::where('type', 'gift')->with(['wallet.user', 'gift'])->get()->map(fn ($t) => $this->giftTransaction($t)),
            'gift-earnings' => $this->giftEarnings(),
            'settings' => collect([$this->settings()]),
            'featured', 'cms', 'notifications', 'adjustments', 'risk-alerts' => AdminConsoleRecord::where('resource', $resource)->get()->map(fn ($r) => [...$r->payload, 'id' => (string) $r->id]),
            default => abort(404, 'Unknown console resource.'),
        };
    }

    public function user(User $u): array
    {
        return ['id' => (string) $u->id, 'name' => $u->name, 'avatar' => $u->avatar, 'username' => $u->username ?? '', 'email' => $u->email,
            'phone' => $u->phone ?? '', 'country' => $u->country ?? $u->country_code ?? '',
            'accountType' => $u->roles->contains('name', 'creator') ? 'Creator' : 'Viewer',
            'verification' => $u->verified ? 'Verified' : ($u->console_verification === 'Pending' ? 'Pending' : 'Unverified'),
            'status' => $u->console_status, 'followers' => $u->followers_count ?? $u->followers()->count(),
            'contentCount' => $u->videos_count ?? $u->videos()->count(), 'registeredAt' => $this->iso($u->created_at), 'lastActiveAt' => $this->iso($u->last_seen_at ?? $u->created_at),
        ];
    }

    public function creator(User $u): array
    {
        $views = (int) $u->videos()->sum('views_count');
        return ['id' => (string) $u->id, 'name' => $u->name, 'avatar' => $u->avatar, 'username' => $u->username ?? '', 'country' => $u->country ?? '',
            'verification' => $u->verified ? 'Public badge active' : 'No public badge', 'status' => $u->console_status,
            'followers' => $u->followers_count ?? $u->followers()->count(), 'views' => $views,
            'engagement' => $views ? round($u->videos()->sum('likes_count') / $views * 100, 2) : 0,
            'videos' => $u->videos_count ?? $u->videos()->count(), 'liveStreams' => LiveSession::where('creator_id', $u->id)->count(),
            'events' => $u->events_count ?? $u->events()->count(), 'subscribers' => $u->subscribers_count ?? $u->subscribers()->count(),
            'earnings' => (float) WalletTransaction::whereHas('counterpartyWallet', fn ($q) => $q->where('user_id', $u->id))->where('status', 'completed')->sum('net_usd_amount'),
            'hasStore' => false, 'reports' => SignalReport::where('reportable_type', User::class)->where('reportable_id', $u->id)->count(), 'joinedAt' => $this->iso($u->created_at),
        ];
    }

    public function video($v): array
    {
        $meta = $v->metadata ?? [];
        return ['id' => (string) $v->id, 'creatorId' => (string) $v->user_id, 'title' => $v->title ?: $v->caption ?: 'Untitled',
            'creator' => $v->user?->name ?? 'Deleted user', 'category' => $v->content_type ?? 'General',
            'kind' => $v->visibility === 'premium' ? 'Premium' : 'Video', 'views' => (int) $v->views_count,
            'likes' => (int) $v->likes_count, 'comments' => (int) $v->comments_count, 'shares' => (int) ($meta['shares_count'] ?? 0),
            'visibility' => $this->title($v->visibility), 'status' => $meta['console_status'] ?? match ($v->status) {'ready' => 'Published', 'failed' => 'Rejected', default => $this->title($v->status)},
            'price' => (float) ($meta['price_ghs'] ?? 0), 'purchases' => (int) ($meta['purchases'] ?? 0), 'revenue' => (float) ($meta['revenue_ghs'] ?? 0),
            'publishedAt' => $this->iso($v->created_at), 'reports' => SignalReport::where('reportable_type', get_class($v))->where('reportable_id', $v->id)->count(),
            'durationSeconds' => (int) ($v->duration ?? 0), 'playbackUrl' => $v->playback_url, 'thumbnailUrl' => $v->thumbnail_url,
        ];
    }

    public function stream($s): array
    {
        return ['id' => (string) $s->id, 'creatorId' => (string) $s->creator_id, 'title' => $s->title, 'creator' => $s->creator?->name ?? 'Deleted user', 'category' => $s->category ?? 'General',
            'currentViewers' => $s->current_viewers, 'peakViewers' => $s->peak_viewers,
            'durationSeconds' => $s->started_at ? (int) $s->started_at->diffInSeconds($s->ended_at ?? now()) : 0,
            'status' => $this->title($s->status), 'startedAt' => $this->iso($s->started_at ?? $s->scheduled_at ?? $s->created_at),
            'reports' => SignalReport::where('reportable_type', LiveSession::class)->where('reportable_id', $s->id)->count(), 'chatEnabled' => $s->chat_enabled,
        ];
    }

    public function event($e): array
    {
        return ['id' => (string) $e->id, 'organizerId' => (string) $e->user_id, 'name' => $e->title, 'organizer' => $e->creator?->name ?? 'Deleted user',
            'date' => $this->iso($e->starts_at), 'time' => $e->starts_at?->format('H:i') ?? '', 'venue' => $e->venue_name ?: $e->venue_type,
            'ticketPrice' => $e->currency === 'GHS' ? (float) collect($e->ticket_types ?? [])->min('price') : 0,
            'ticketsSold' => $e->tickets_sold, 'capacity' => $e->capacity ?? 0, 'revenue' => (float) ($e->purchases_sum_total_amount ?? 0), 'status' => $this->title($e->status),
        ];
    }

    public function transaction($t): array
    {
        return ['id' => (string) $t->id, 'userId' => (string) $t->user_id, 'creatorId' => (string) $t->counterpartyWallet?->user_id, 'user' => $t->wallet?->user?->name ?? 'System', 'creator' => $t->counterpartyWallet?->user?->name ?? 'Platform',
            'type' => $this->title($t->type), 'amount' => (float) $t->usd_amount, 'platformFee' => (float) $t->platform_fee_usd,
            'creatorAmount' => (float) $t->net_usd_amount, 'provider' => $t->metadata['provider'] ?? 'Wallet',
            'status' => $t->status === 'completed' ? 'Successful' : $this->title($t->status), 'date' => $this->iso($t->created_at),
        ];
    }

    public function giftTransaction($t): array
    {
        $creator = User::find($t->metadata['creator_id'] ?? null);
        $creatorValue = (float) ($t->metadata['creator_earnings_ghs'] ?? $t->metadata['creator_earnings_usd'] ?? $t->usd_amount);
        $coinValueGhs = (float) ($t->metadata['coin_value_ghs'] ?? ($this->conversion->ghsPerCoin() ?: config('kulcoin.coin_to_usd_rate', 0.01)));
        return ['id' => (string) $t->id, 'sender' => $t->wallet?->user?->name ?? 'Deleted user', 'creator' => $creator?->name ?? 'Deleted user',
            'gift' => $t->gift?->name ?? $t->metadata['gift_name'] ?? 'Deleted gift', 'quantity' => (int) ($t->metadata['quantity'] ?? 1), 'coins' => $t->coin_amount,
            'creatorValue' => $creatorValue, 'platformValue' => max(0, $t->coin_amount * $coinValueGhs - $creatorValue),
            'context' => isset($t->metadata['live_session_id']) ? 'Live Stream' : 'Video', 'contextRef' => (string) ($t->metadata['live_session_id'] ?? $t->metadata['video_id'] ?? ''),
            'status' => $t->metadata['console_flagged'] ?? false ? 'Flagged' : $this->title($t->status), 'date' => $this->iso($t->created_at),
        ];
    }

    public function giftEarnings(): Collection
    {
        $gifts = KulCoinTransaction::where('type', 'gift')->where('status', 'completed')->with(['wallet.user', 'gift'])->get();
        $currentCoinValueGhs = $this->conversion->ghsPerCoin() ?: (float) config('kulcoin.coin_to_usd_rate', 0.01);
        return $gifts->groupBy(fn ($t) => $t->metadata['creator_id'] ?? '')->filter(fn ($items, $id) => $id !== '')->map(function ($items, $id) use ($currentCoinValueGhs) {
            $u = User::find($id); $wallet = $u?->wallet;
            $earnings = (float) $items->sum(fn ($t) => (float) ($t->metadata['creator_earnings_ghs'] ?? $t->metadata['creator_earnings_usd'] ?? $t->usd_amount));
            $pending = (float) DB::table('wallet_ledger_entries')->where('wallet_id', $wallet?->id)->where('balance_bucket', 'pending')->where('entry_type', 'credit')->whereNull('settled_at')->sum('amount_usd');
            return ['id' => (string) $id, 'creator' => $u?->name ?? 'Deleted user', 'giftsReceived' => $items->sum(fn ($t) => (int) ($t->metadata['quantity'] ?? 1)),
                'coinsReceived' => $items->sum('coin_amount'), 'grossValue' => $items->sum(fn ($t) => (int) $t->coin_amount * (float) ($t->metadata['coin_value_ghs'] ?? $currentCoinValueGhs)),
                'creatorEarnings' => $earnings, 'pendingSettlement' => min($pending, $earnings), 'settled' => max(0, $earnings - $pending),
                'topGift' => $items->groupBy('gift_id')->sortByDesc(fn ($group) => $group->sum('coin_amount'))->first()?->first()?->gift?->name ?? '',
                'supporters' => $items->pluck('user_id')->unique()->count(),
            ];
        })->values();
    }

    public function settings(): array
    {
        return AdminConsoleRecord::where('resource', 'settings')->first()?->payload ?? [
            'id' => 'platform', 'platformName' => config('app.name'), 'supportEmail' => config('mail.from.address'),
            'registrationsEnabled' => true, 'liveEnabled' => true,
        ];
    }

    public function title($value): string
    {
        return Str::title(str_replace('_', ' ', $value instanceof \BackedEnum ? $value->value : (string) $value));
    }

    public function iso($date): string
    {
        return $date ? \Carbon\Carbon::parse($date)->toIso8601String() : '';
    }
}
