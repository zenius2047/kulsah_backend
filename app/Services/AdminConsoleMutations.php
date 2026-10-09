<?php

namespace App\Services;

use App\Events\AdminConsoleDataChanged;
use App\Jobs\SendAdminConsoleCampaign;
use App\Models\AdminConsoleAudit;
use App\Models\AdminConsoleRecord;
use App\Models\Challenge;
use App\Models\CommunityPost;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\KulCoinGift;
use App\Models\KulCoinLedgerEntry;
use App\Models\KulCoinPackage;
use App\Models\KulCoinTransaction;
use App\Models\KulCoinWallet;
use App\Models\LiveSession;
use App\Models\Payment;
use App\Models\Role;
use App\Models\SignalReport;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Video;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminConsoleMutations
{
    public function __construct(private AdminConsoleAccess $access, private AdminConsoleResources $resources) {}

    public function audit(Request $request, User $actor, string $action, string $entity, string $id, array $before, array $after): void
    {
        AdminConsoleAudit::create(['admin_id' => $actor->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'previous_value' => $before, 'new_value' => $after, 'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 2000)]);
    }

    public function save(Request $request, User $actor, string $resource, ?string $id = null): array
    {
        $permission = match ($resource) {
            'packages' => 'kulcoin.manage', 'gifts' => 'gifts.manage', 'cms', 'featured' => 'cms.edit', 'adjustments' => 'kulcoin.adjust', 'notifications' => 'notifications.send', 'admins', 'roles' => 'admins.manage', 'settings' => 'settings.edit', default => abort(404)
        };
        $this->access->authorize($actor, $permission);

        return DB::transaction(function () use ($request, $actor, $resource, $id) {
            if ($resource === 'packages') {
                $v = $request->validate(['name' => 'required|string|max:255', 'coins' => 'required|integer|min:1|max:100000000', 'bonus' => 'required|integer|min:0|max:100000000',
                    'price' => 'required|numeric|min:0.01|max:10000000', 'currency' => 'required|in:GHS', 'discount' => 'required|numeric|min:0|max:100',
                    'order' => 'required|integer|min:0', 'status' => 'required|in:Active,Inactive,Archived']);
                $model = $id ? KulCoinPackage::lockForUpdate()->findOrFail($id) : new KulCoinPackage;
                $before = $model->getAttributes();
                $model->fill(['code' => $model->code ?: 'console-'.Str::uuid(), 'name' => $v['name'], 'coin_amount' => $v['coins'], 'bonus_coin_amount' => $v['bonus'],
                    'usd_price' => $v['price'], 'currency_code' => 'GHS', 'is_active' => $v['status'] === 'Active', 'sort_order' => $v['order'],
                    'metadata' => [...($model->metadata ?? []), 'discount' => $v['discount'], 'console_status' => $v['status']]])->save();
            } elseif ($resource === 'gifts') {
                $v = $request->validate(['name' => 'required|string|max:255', 'emoji' => 'required|string|max:30', 'image' => 'nullable|string|max:2800000',
                    'description' => 'nullable|string|max:2000', 'category' => 'required|in:romance,hype,achievement,premium,featured,fashion,food',
                    'rarity' => 'required|in:Common,Rare,Epic,Legendary,Mythic', 'coinPrice' => 'required|integer|min:1|max:100000000',
                    'creatorShare' => 'required|integer|min:0|max:100', 'animation' => 'required|in:Static,Animated,Full-screen',
                    'contexts' => 'required|array|min:1', 'contexts.*' => 'in:Live Stream,Video,Live Battle,Profile,Event', 'status' => 'required|in:Active,Inactive,Scheduled,Archived']);
                $image = $v['image'] ?? null;
                if ($image && preg_match('~^data:image/(png|jpeg|webp|gif);base64,([A-Za-z0-9+/=]+)$~D', $image, $imageMatch)) {
                    $imageBytes = base64_decode($imageMatch[2], true);
                    abort_if($imageBytes === false, 422, 'The gift image data is invalid. Please choose the image again.');
                    $imageInfo = @getimagesizefromstring($imageBytes);
                    $mime = $imageInfo['mime'] ?? null;
                    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    abort_unless(isset($extensions[$mime]), 422, 'Use a valid PNG, JPG, WebP or GIF image.');

                    $imagePath = 'kulsah/gifts/'.Str::uuid().'.'.$extensions[$mime];
                    $imageDisk = Storage::disk('s3');
                    // Match the existing S3 profile-image uploads: the bucket policy controls public reads,
                    // so this write does not require a per-object ACL permission.
                    abort_unless($imageDisk->put($imagePath, $imageBytes), 500, 'The gift image could not be stored in primary media storage.');
                    $image = $imageDisk->url($imagePath);
                } else {
                    abort_if($image && ! preg_match('~^https?://~i', $image), 422, 'Use a raster image or an HTTP image URL.');
                    abort_if($image && strlen($image) > 255, 422, 'The image URL is too long. Upload the image file instead.');
                }
                $model = $id ? KulCoinGift::lockForUpdate()->findOrFail($id) : new KulCoinGift;
                $before = $model->getAttributes();
                $giftMetadata = $v;
                unset($giftMetadata['image']);
                $model->fill(['code' => $model->code ?: 'console-'.Str::uuid(), 'name' => $v['name'], 'category' => $v['category'], 'coin_cost' => $v['coinPrice'],
                    'icon_url' => $image, 'is_active' => $v['status'] === 'Active',
                    'metadata' => [...($model->metadata ?? []), ...$giftMetadata, 'console_status' => $v['status']]])->save();
            } elseif ($resource === 'admins') {
                $v = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|string|email|max:255', 'role' => ['required', Rule::in(AdminConsoleAccess::ROLES)]]);
                $email = mb_strtolower(trim($v['email']));
                $model = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if (! $model) {
                    $usernameBase = Str::slug(strstr($email, '@', true) ?: 'admin');
                    do { $username = substr($usernameBase, 0, 35).'-'.Str::lower(Str::random(8)); }
                    while (User::where('username', $username)->exists());
                    $model = new User;
                    $model->forceFill(['name' => $v['name'], 'email' => $email, 'username' => $username,
                        'password' => Hash::make(Str::random(64)), 'activated' => false])->save();
                }
                $before = $model->only(['name', 'admin_console_role', 'admin_console_disabled']);
                abort_if((int) $model->id === (int) $actor->id && $v['role'] !== 'Super Admin', 422, 'You cannot remove your own Super Admin access.');
                $model->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => 'admin'])->id]);
                $model->forceFill(['name' => $v['name'], 'admin_console_role' => $v['role'], 'admin_console_disabled' => false])->save();
                $invitationSent = ! $model->activated;
                if ($invitationSent) {
                    $token = Str::random(64);
                    DB::table('admin_invitations')->where('user_id', $model->id)->whereNull('accepted_at')->delete();
                    DB::table('admin_invitations')->insert(['user_id' => $model->id, 'created_by' => $actor->id,
                        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48), 'created_at' => now(), 'updated_at' => now()]);
                    $inviteUrl = rtrim(config('admin-console.frontend_url'), '/').'/admin-invite?token='.urlencode($token);
                    $inviteEmail = $email;
                    $inviteName = $v['name'];
                    $inviteRole = $v['role'];
                    DB::afterCommit(function () use ($inviteEmail, $inviteName, $inviteRole, $inviteUrl): void {
                        try {
                            Mail::send([
                                'html' => 'emails.admin-invitation',
                                'text' => 'emails.admin-invitation-text',
                            ], ['name' => $inviteName, 'role' => $inviteRole, 'inviteUrl' => $inviteUrl], function ($message) use ($inviteEmail): void {
                                $message->to($inviteEmail)->subject('Your Kulsah administrator invitation');
                            });
                        } catch (\Throwable $exception) { report($exception); }
                    });
                }
            } else {
                $v = match ($resource) {
                    'cms' => $request->validate(['kind' => 'required|in:Banner,Announcement,FAQ,Page', 'title' => 'required|string|max:255', 'body' => 'required|string|max:20000', 'placement' => 'required|string|max:255', 'status' => 'required|in:Draft,Published,Scheduled,Archived']),
                    'featured' => $request->validate(['collection' => 'required|string|max:100', 'title' => 'required|string|max:255', 'targetResource' => 'required|in:videos,creators,events,challenges', 'targetId' => 'required|string', 'position' => 'required|integer|min:1', 'priority' => 'required|in:Low,Normal,High', 'startDate' => 'required|date', 'endDate' => 'required|date|after:startDate', 'active' => 'required|boolean']),
                    'adjustments' => $request->validate(['userId' => 'required|integer|exists:users,id', 'coins' => 'required|integer|min:1|max:10000000', 'kind' => 'required|in:Credit,Debit,Promotional', 'reason' => 'required|string|min:10|max:1000']),
                    'notifications' => $request->validate(['title' => 'required|string|min:4|max:255', 'message' => 'required|string|min:10|max:2000', 'audience' => 'required|in:All users,Creators,Viewers', 'channel' => 'required|in:In-app']),
                    'roles' => $request->validate(['name' => ['required', Rule::in(array_diff(AdminConsoleAccess::ROLES, ['Super Admin']))], 'permissions' => 'required|array', 'permissions.*' => ['required', 'distinct', Rule::in(AdminConsoleAccess::PERMISSIONS)]]),
                    'settings' => $request->validate(['platformName' => 'required|string|max:100', 'supportEmail' => 'required|email', 'registrationsEnabled' => 'required|boolean', 'liveEnabled' => 'required|boolean']),
                    default => abort(404),
                };
                if ($resource === 'adjustments') {
                    abort_if($id, 422, 'Submitted adjustments cannot be edited.');
                    $v = [...$v, 'user' => User::findOrFail($v['userId'])->name, 'requestedBy' => $actor->name, 'requesterId' => $actor->id,
                        'approvedBy' => null, 'status' => 'Pending Approval', 'date' => now()->toIso8601String()];
                }
                if ($resource === 'notifications') {
                    $v = [...$v, 'type' => 'System', 'schedule' => 'Immediate', 'sendAt' => now()->toIso8601String(), 'delivered' => 0, 'opened' => 0, 'status' => 'Draft'];
                }
                if ($resource === 'cms') {
                    $v = [...$v, 'updatedAt' => now()->toIso8601String(), 'updatedBy' => $actor->name];
                }
                $model = $id ? AdminConsoleRecord::where('resource', $resource)->lockForUpdate()->findOrFail($id) : new AdminConsoleRecord;
                if ($resource === 'settings') {
                    $model = AdminConsoleRecord::firstOrNew(['resource' => 'settings']);
                }
                if ($resource === 'roles') {
                    $model = AdminConsoleRecord::where('resource', 'roles')->where('payload->name', $v['name'])->first() ?? new AdminConsoleRecord;
                }
                $before = $model->payload ?? [];
                $model->fill(['resource' => $resource, 'payload' => $v, 'created_by' => $actor->id])->save();
            }
            $after = $model instanceof AdminConsoleRecord ? $model->payload : $model->getAttributes();
            // Passwords and activation secrets never enter an audit record.
            if ($model instanceof User) {
                $after = $model->only(['name', 'admin_console_role', 'admin_console_disabled']);
            }
            $this->audit($request, $actor, $id ? 'update' : 'create', $resource, (string) $model->id, $before, $after);
            DB::afterCommit(function () use ($resource, $id, $actor): void {
                try {
                    event(new AdminConsoleDataChanged($resource, $id ? 'update' : 'create', (string) $actor->id));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            if ($resource === 'admins') {
                return [...($this->resources->rows($resource)->firstWhere('id', (string) $model->id) ?? $after), 'invitationSent' => $invitationSent];
            }
            return $resource === 'settings' ? $after : ($this->resources->rows($resource)->firstWhere('id', (string) $model->id) ?? $after);
        });
    }

    public function act(Request $request, User $actor, string $resource, string $action, array $ids, string $reason): void
    {
        $permission = match ($resource) {
            'users' => 'users.suspend', 'creators' => in_array($action, ['verify', 'revoke', 'reject', 'review'], true) ? 'creators.verify' : 'creators.suspend',
            'videos' => match ($action) {
                'approve' => 'content.approve', 'remove' => 'content.remove', default => 'content.edit'
            },
            'community-posts' => $action === 'remove' ? 'content.remove' : 'content.edit',
            'streams', 'challenges', 'featured' => 'content.edit', 'events' => $action === 'approve' ? 'events.approve' : 'events.edit',
            'tickets' => 'tickets.refund', 'subscriptions', 'withdrawals' => 'finance.payout', 'transactions', 'wallets' => 'finance.adjust',
            'reports' => 'moderation.resolve', 'packages', 'purchases' => 'kulcoin.manage', 'gifts', 'gift-transactions' => 'gifts.manage',
            'balances', 'adjustments' => 'kulcoin.adjust', 'cms' => 'cms.edit', 'notifications' => 'notifications.send', 'admins' => 'admins.manage', default => abort(404),
        };
        $this->access->authorize($actor, $permission);
        DB::transaction(function () use ($request, $actor, $resource, $action, $ids) {
            foreach ($ids as $id) {
                $model = match ($resource) {
                    'users', 'creators', 'admins' => User::lockForUpdate()->findOrFail($id), 'videos' => Video::lockForUpdate()->findOrFail($id),
                    'community-posts' => CommunityPost::lockForUpdate()->findOrFail($id),
                    'streams' => LiveSession::lockForUpdate()->findOrFail($id), 'challenges' => Challenge::lockForUpdate()->findOrFail($id),
                    'events' => Event::lockForUpdate()->findOrFail($id), 'tickets' => EventTicket::lockForUpdate()->findOrFail($id),
                    'subscriptions' => Subscription::lockForUpdate()->findOrFail($id), 'transactions', 'withdrawals' => WalletTransaction::lockForUpdate()->findOrFail($id),
                    'wallets' => Wallet::lockForUpdate()->findOrFail($id), 'reports' => SignalReport::lockForUpdate()->findOrFail($id),
                    'packages' => KulCoinPackage::lockForUpdate()->findOrFail($id), 'gifts' => KulCoinGift::lockForUpdate()->findOrFail($id),
                    'gift-transactions', 'purchases' => KulCoinTransaction::lockForUpdate()->findOrFail($id), 'balances' => KulCoinWallet::lockForUpdate()->findOrFail($id),
                    'cms', 'featured', 'notifications', 'adjustments' => AdminConsoleRecord::where('resource', $resource)->lockForUpdate()->findOrFail($id), default => abort(404),
                };
                $before = $model instanceof User ? $model->only(['console_status', 'console_verification', 'verified', 'admin_console_disabled']) : $model->getAttributes();
                if (in_array($resource, ['users', 'creators'], true)) {
                    $changes = match ($action) {
                        'suspend' => ['console_status' => 'Suspended'], 'ban' => ['console_status' => 'Banned'], 'restore' => ['console_status' => 'Active'],
                        // This action controls the public creator badge only. KYC is stored and
                        // reviewed independently through KycApplicationController.
                        'verify' => ['verified' => true, 'verified_at' => now()],
                        'revoke' => ['verified' => false, 'verified_at' => null],
                        'reject' => ['verified' => false, 'verified_at' => null],
                        'review' => abort(422, 'Use the KYC application review workflow.'),
                        default => abort(422, 'Unsupported account action.'),
                    };
                    abort_if($model->id === $actor->id && in_array($action, ['suspend', 'ban'], true), 422, 'You cannot suspend your own account.');
                    $model->forceFill($changes)->save();
                    if (in_array($action, ['suspend', 'ban'], true)) {
                        $model->tokens()->delete();
                    }
                } elseif ($resource === 'admins') {
                    abort_unless(in_array($action, ['disable', 'enable'], true), 422, 'Unsupported administrator action.');
                    abort_if($model->id === $actor->id, 422, 'You cannot disable your own access.');
                    $model->forceFill(['admin_console_disabled' => $action === 'disable'])->save();
                    if ($action === 'disable') {
                        $model->tokens()->where('name', 'admin-console')->delete();
                    }
                } elseif ($resource === 'community-posts') {
                    $model->status = match ($action) {
                        'hide' => 'hidden', 'restore' => 'published', 'remove' => 'removed',
                        default => abort(422, 'Unsupported community post action.'),
                    };
                    $model->save();
                } elseif ($resource === 'videos') {
                    $meta = $model->metadata ?? [];
                    if ($action === 'feature') {
                        $this->feature('videos', $model, $actor);
                    } else {
                        $status = match ($action) {
                            'approve' => 'Published', 'hide' => 'Hidden', 'remove' => 'Removed', default => abort(422)
                        };
                        if ($action === 'approve') {
                            abort_unless($model->processing_status?->value === 'ready', 422, 'Video processing must finish before approval.');
                        }
                        $model->fill(['status' => $action === 'approve' ? 'ready' : strtolower($status), 'metadata' => [...$meta, 'console_status' => $status]])->save();
                    }
                    app(VideoCacheService::class)->invalidateCreator((int) $model->user_id);
                } elseif ($resource === 'streams') {
                    if ($action === 'toggle-chat') {
                        $model->chat_enabled = ! $model->chat_enabled;
                    } elseif (in_array($action, ['stop', 'terminate'], true)) {
                        $model = app(LiveSessionService::class)->end($model, $action === 'terminate' ? 'platform_terminated' : 'admin_stopped');
                    } else {
                        abort(422, 'Unsupported live action.');
                    }
                    $model->save();
                } elseif ($resource === 'events') {
                    if ($action === 'feature') {
                        $this->feature('events', $model, $actor);
                    } else {
                        $model->fill(['status' => match ($action) {
                            'approve' => 'published', 'cancel' => 'cancelled', default => abort(422)
                        }])->save();
                    }
                } elseif ($resource === 'challenges') {
                    if ($action === 'feature') {
                        $this->feature('challenges', $model, $actor);
                    } else {
                        $status = match ($action) {
                            'suspend' => 'paused', 'end' => 'submissions_closed', default => abort(422)
                        };
                        $model->forceFill(['status' => $status, 'submission_ends_at' => now()])->save();
                    }
                } elseif (in_array($resource, ['wallets', 'balances'], true)) {
                    abort_unless($action === 'toggle-freeze', 422, 'Use an audited ledger adjustment to change balances.');
                    $model->status = $model->status === 'frozen' ? 'active' : 'frozen';
                    $model->save();
                } elseif ($resource === 'subscriptions') {
                    abort_unless($action === 'cancel', 422, 'Only cancellation is available for this subscription.');
                    $model->status = 'cancelled';
                    $model->save();
                } elseif ($resource === 'tickets') {
                    if ($action === 'check-in') {
                        $model->verified_at = $model->verified_at ? null : now();
                        $model->verified_by = $model->verified_at ? $actor->id : null;
                        $model->status = $model->verified_at ? 'used' : 'paid';
                    } elseif ($action === 'invalidate') {
                        $model->status = 'invalid';
                    } else {
                        abort(422, 'Unsupported ticket action.');
                    }
                    $model->save();
                } elseif ($resource === 'withdrawals') {
                    abort_unless($model->type === 'withdrawal' && in_array($model->status, ['pending', 'under_review'], true), 422, 'This withdrawal cannot be reviewed.');
                    if ($action === 'approve' && $model->wallet?->user) {
                        app(CountrySettingsService::class)->assertWithdrawalAllowed($model->wallet->user, (float) $model->local_amount, (string) $model->local_currency);
                    }
                    $model->status = match ($action) {
                        'approve' => 'approved', 'review' => 'under_review', 'reject' => 'rejected', default => abort(422)
                    };
                    $model->performed_by_user_id = $actor->id;
                    $model->save();
                } elseif ($resource === 'transactions' || $resource === 'gift-transactions') {
                    abort_unless($action === 'flag', 422, 'Refunds and reversals require the settlement workflow.');
                    $model->metadata = [...($model->metadata ?? []), 'console_flagged' => true];
                    $model->save();
                } elseif ($resource === 'purchases') {
                    abort_unless($action === 'verify', 422, 'Refunds require the payment provider workflow.');
                    $payment = Payment::where('reference', $model->metadata['payment_reference'] ?? '')->firstOrFail();
                    app(PaymentService::class)->verify($payment);
                } elseif (in_array($resource, ['packages', 'gifts'], true)) {
                    $status = match ($action) {
                        'activate' => 'Active', 'deactivate' => 'Inactive', 'archive' => 'Archived', default => abort(422)
                    };
                    $model->is_active = $status === 'Active';
                    $model->metadata = [...($model->metadata ?? []), 'console_status' => $status];
                    $model->save();
                } elseif ($resource === 'reports') {
                    if ($action === 'remove') {
                        $target = $model->reportable;
                        abort_unless($target instanceof Video, 422, 'Use the appropriate domain workflow for this report target.');
                        $target->status = 'removed';
                        $target->save();
                        app(VideoCacheService::class)->invalidateCreator((int) $target->user_id);
                        $model->status = 'resolved';
                    } elseif ($action === 'suspend') {
                        $target = $model->reportable;
                        $u = $target instanceof User ? $target : ($target?->user ?? $target?->creator);
                        abort_unless($u && $u->id !== $actor->id, 422, 'No eligible account is attached to this report.');
                        $u->forceFill(['console_status' => 'Suspended'])->save();
                        $u->tokens()->delete();
                        $model->status = 'resolved';
                    } else {
                        $model->status = match ($action) {
                            'assign' => 'under_review', 'dismiss' => 'rejected', 'escalate' => 'escalated', default => abort(422)
                        };
                    }
                    $model->save();
                } elseif ($resource === 'adjustments') {
                    $payload = $model->payload;
                    abort_unless($payload['status'] === 'Pending Approval', 422, 'This adjustment has already been reviewed.');
                    abort_if((int) $payload['requesterId'] === $actor->id, 422, 'A different administrator must review this adjustment.');
                    if ($action === 'approve') {
                        $this->adjustCoins($payload, $model->id, $actor);
                    } elseif ($action !== 'reject') {
                        abort(422);
                    }
                    $model->payload = [...$payload, 'status' => $action === 'approve' ? 'Applied' : 'Rejected', 'approvedBy' => $actor->name];
                    $model->save();
                } elseif ($resource === 'featured') {
                    $p = $model->payload;
                    if ($action === 'remove') {
                        $model->delete();
                    } else {
                        $model->payload = match ($action) {
                            'up' => [...$p, 'position' => max(1, $p['position'] - 1)], 'down' => [...$p, 'position' => $p['position'] + 1],
                            'toggle' => [...$p, 'active' => ! $p['active']], default => abort(422),
                        };
                        $model->save();
                    }
                } elseif ($resource === 'cms') {
                    $model->payload = [...$model->payload, 'status' => match ($action) {
                        'publish' => 'Published', 'unpublish' => 'Draft', 'archive' => 'Archived', default => abort(422)
                    }, 'updatedAt' => now()->toIso8601String(), 'updatedBy' => $actor->name];
                    $model->save();
                } elseif ($resource === 'notifications') {
                    if ($action === 'duplicate') {
                        $copy = $model->replicate();
                        $copy->payload = [...$model->payload, 'status' => 'Draft', 'delivered' => 0, 'opened' => 0];
                        $copy->save();
                    } elseif ($action === 'send') {
                        abort_unless($model->payload['status'] === 'Draft', 422, 'This campaign has already been sent.');
                        SendAdminConsoleCampaign::dispatch($model->id)->afterCommit();
                        $model->payload = [...$model->payload, 'status' => 'Sending'];
                        $model->save();
                    } else {
                        abort(422, 'Only drafts can be sent or duplicated.');
                    }
                } else {
                    abort(422, 'Unsupported action.');
                }
                $after = $model instanceof User ? $model->only(['console_status', 'console_verification', 'verified', 'admin_console_disabled']) : $model->getAttributes();
                $this->audit($request, $actor, $action, $resource, $id, $before, $after);
            }
            DB::afterCommit(function () use ($resource, $action, $actor): void {
                try {
                    event(new AdminConsoleDataChanged($resource, $action, (string) $actor->id));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        });
    }

    private function feature(string $resource, $model, User $actor): void
    {
        AdminConsoleRecord::create(['resource' => 'featured', 'created_by' => $actor->id, 'payload' => ['targetResource' => $resource,
            'targetId' => (string) $model->id, 'title' => $model->title ?: $model->name, 'collection' => $resource === 'events' ? 'Featured Events' : 'Featured Videos',
            'position' => AdminConsoleRecord::where('resource', 'featured')->count() + 1, 'priority' => 'Normal', 'startDate' => now()->toIso8601String(), 'endDate' => now()->addMonth()->toIso8601String(), 'active' => true]]);
    }

    private function adjustCoins(array $data, int $id, User $actor): void
    {
        $service = app(KulCoinService::class);
        $wallet = $service->getOrCreateUserWallet(User::findOrFail($data['userId']));
        $issuer = $service->getOrCreateSystemWallet(config('kulcoin.issuer_account_key'), 'KulCoin Issuer');
        $locked = KulCoinWallet::whereIn('id', [$wallet->id, $issuer->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $wallet = $locked[$wallet->id];
        $issuer = $locked[$issuer->id];
        abort_unless($wallet->status === 'active', 422, 'Unfreeze the coin wallet before adjusting it.');
        $bucket = $data['kind'] === 'Promotional' ? 'bonus' : 'available';
        $column = $bucket === 'bonus' ? 'bonus_balance_kc' : 'available_balance_kc';
        $sign = $data['kind'] === 'Debit' ? -1 : 1;
        abort_if($wallet->$column + $sign * $data['coins'] < 0, 422, 'The account has insufficient coins.');
        $tx = KulCoinTransaction::create(['reference' => (string) Str::uuid(), 'idempotency_key' => 'admin-adjustment-'.$id,
            'type' => $data['kind'] === 'Debit' ? 'admin_debit' : 'admin_credit', 'status' => 'completed', 'user_id' => $data['userId'],
            'counterparty_wallet_id' => $issuer->id, 'coin_amount' => $data['coins'], 'net_coin_amount' => $data['coins'], 'description' => $data['reason'],
            'performed_by_user_id' => $actor->id, 'processed_at' => now()]);
        foreach ([[$wallet, $sign], [$issuer, -$sign]] as [$w, $s]) {
            $w->$column += $s * $data['coins'];
            $w->last_ledger_at = now();
            $w->save();
            KulCoinLedgerEntry::create(['kulcoin_transaction_id' => $tx->id, 'kulcoin_wallet_id' => $w->id, 'entry_type' => $s > 0 ? 'credit' : 'debit',
                'balance_bucket' => $bucket, 'amount_kc' => $data['coins'], 'running_balance_kc' => $w->$column, 'narration' => $data['reason']]);
        }
    }
}
