<?php

namespace App\Services;

use App\Models\AdminConsoleAudit;
use App\Models\AdminConsoleRecord;
use App\Models\User;
use App\Notifications\AnchorProgramNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnchorKulsanService
{
    private const METRICS = ['followers', 'videos', 'views', 'active_days', 'referrals'];

    public function currentConfiguration(): ?object
    {
        $row = DB::table('anchor_kulsan_configurations')->where('active', true)->orderByDesc('version')->first();
        return $row ? $this->formatConfiguration($row) : null;
    }

    public function saveConfiguration(User $actor, array $input): object
    {
        $enabled = array_values(array_filter($input['milestones'], fn (array $item) => $item['enabled']));
        if (! count($enabled)) {
            throw ValidationException::withMessages(['milestones' => 'Enable at least one milestone before activating this configuration.']);
        }
        if (collect($enabled)->contains(fn (array $item) => (int) $item['target'] < 1)) {
            throw ValidationException::withMessages(['milestones' => 'Every enabled milestone needs a target greater than zero.']);
        }
        if (! collect($enabled)->contains(fn (array $item) => $item['type'] === 'referrals')) {
            throw ValidationException::withMessages(['milestones' => 'An activated referral milestone is required for Anchor recognition.']);
        }
        return DB::transaction(function () use ($actor, $input): object {
            if (DB::getDriverName() === 'pgsql') {
                // A transaction-scoped lock also serializes the first insert, when no row exists yet.
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [20261014, 1]);
            } else {
                DB::table('anchor_kulsan_configurations')->orderByDesc('version')->lockForUpdate()->first();
            }
            $version = (int) DB::table('anchor_kulsan_configurations')->max('version') + 1;
            DB::table('anchor_kulsan_configurations')->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            $id = DB::table('anchor_kulsan_configurations')->insertGetId([
                'version' => $version, 'qualification_rule' => $input['qualificationRule'],
                'count_existing_activity' => $input['countExistingActivity'],
                'milestones' => json_encode($input['milestones'], JSON_THROW_ON_ERROR), 'active' => true,
                'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->logAudit($actor, 'configuration.updated', 'anchor_kulsan_configurations', (string) $id, [], [
                'version' => $version, 'qualificationRule' => $input['qualificationRule'], 'milestones' => $input['milestones'],
                'countExistingActivity' => $input['countExistingActivity'],
            ]);
            if ((bool) ($input['applyToExistingCandidates'] ?? false)) {
                $candidateIds = DB::table('anchor_kulsan_memberships')->whereIn('status', ['selected', 'in_progress'])->pluck('id');
                DB::table('anchor_kulsan_memberships')->whereIn('id', $candidateIds)->update(['configuration_id' => $id, 'updated_at' => now()]);
                $this->logAudit($actor, 'configuration.applied_to_candidates', 'anchor_kulsan_configurations', (string) $id, [], [
                    'membershipIds' => $candidateIds->all(), 'candidateCount' => $candidateIds->count(),
                ]);
                foreach ($candidateIds as $candidateId) {
                    $this->evaluateMembership((int) $candidateId, $actor);
                }
            }
            return $this->formatConfiguration(DB::table('anchor_kulsan_configurations')->find($id));
        });
    }

    public function addCandidate(User $actor, int $userId): array
    {
        return DB::transaction(function () use ($actor, $userId): array {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_unless($user->roles()->where('name', 'creator')->exists(), 422, 'Only an existing creator account can join the Anchor program.');
            abort_unless($user->console_status === 'Active', 422, 'Only active creators can join the Anchor program.');
            abort_if(DB::table('anchor_kulsan_memberships')->where('user_id', $user->id)->exists(), 422, 'This creator already has an Anchor program record.');
            $configuration = DB::table('anchor_kulsan_configurations')->where('active', true)->orderByDesc('version')->first();
            abort_unless($configuration, 422, 'Activate a valid milestone configuration before adding Anchor candidates.');
            $configuredMilestones = json_decode($configuration->milestones, true) ?: [];
            abort_unless(collect($configuredMilestones)->contains(fn (array $milestone) => $milestone['type'] === 'referrals' && $milestone['enabled']), 422,
                'Activate a configuration with an enabled referral milestone before adding Anchor candidates.');
            do {
                $referralCode = 'AK'.Str::upper(Str::random(10));
            } while (DB::table('anchor_kulsan_memberships')->where('referral_code', $referralCode)->exists());
            $membershipId = DB::table('anchor_kulsan_memberships')->insertGetId([
                'user_id' => $user->id, 'selected_by' => $actor->id, 'configuration_id' => $configuration?->id,
                'status' => 'selected', 'referral_code' => $referralCode, 'selected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->logAudit($actor, 'candidate.added', 'anchor_kulsan_memberships', (string) $membershipId, [], ['userId' => $user->id, 'configurationVersion' => $configuration?->version, 'referralCode' => $referralCode]);
            $this->notify($user, 'anchor_candidate_selected', 'You have been selected as an Anchor Kulsan candidate. Share your referral code '.$referralCode.' to build referral progress.');
            $this->evaluateMembership($membershipId, $actor);
            return $this->member(DB::table('anchor_kulsan_memberships')->find($membershipId));
        });
    }

    public function act(User $actor, int $userId, string $action, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $userId, $action, $reason): array {
            $membership = DB::table('anchor_kulsan_memberships')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $before = (array) $membership;
            $user = User::findOrFail($userId);
            $changes = ['updated_at' => now()];
            if (in_array($action, ['remove', 'deactivate'], true)) {
                abort_unless(in_array($membership->status, ['selected', 'in_progress'], true), 422, 'Only an active candidate can be deactivated.');
                $changes += [
                    'status' => 'deactivated', 'status_before_deactivation' => $membership->status,
                    'deactivated_at' => now(), 'deactivated_by' => $actor->id, 'deactivation_reason' => $reason,
                ];
                $auditAction = 'candidate.deactivated';
            } elseif ($action === 'activate') {
                abort_unless($membership->status === 'deactivated', 422, 'Only a deactivated candidate can be activated.');
                $previousStatus = in_array($membership->status_before_deactivation, ['selected', 'in_progress'], true)
                    ? $membership->status_before_deactivation
                    : 'in_progress';
                $changes += [
                    'status' => $previousStatus, 'reactivated_at' => now(),
                    'reactivated_by' => $actor->id, 'reactivation_reason' => $reason,
                ];
                $auditAction = 'candidate.reactivated';
            } elseif ($action === 'revoke') {
                abort_unless($membership->status === 'recognized' && $membership->badge_status === 'active', 422, 'Only an active recognized badge can be revoked.');
                $changes += ['badge_status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'featured' => false];
                $auditAction = 'badge.revoked';
            } elseif ($action === 'restore') {
                abort_unless($membership->status === 'recognized' && $membership->badge_status === 'revoked', 422, 'Only a revoked Anchor badge can be restored.');
                $changes += ['badge_status' => 'active', 'restored_at' => now(), 'restored_by' => $actor->id, 'restoration_reason' => $reason];
                $auditAction = 'badge.restored';
            } elseif ($action === 'feature' || $action === 'unfeature') {
                abort_unless($membership->status === 'recognized' && $membership->badge_status === 'active', 422, 'Only active recognized creators can be featured.');
                $changes['featured'] = $action === 'feature';
                $auditAction = 'creator.'.($action === 'feature' ? 'featured' : 'unfeatured');
            } else {
                abort(404);
            }
            DB::table('anchor_kulsan_memberships')->where('id', $membership->id)->update($changes);
            if (in_array($action, ['unfeature', 'revoke'], true)) {
                AdminConsoleRecord::query()->where('resource', 'featured')->get()->each(function (AdminConsoleRecord $record) use ($userId): void {
                    if (($record->payload['targetResource'] ?? null) === 'creators' && (string) ($record->payload['targetId'] ?? '') === (string) $userId && ($record->payload['active'] ?? false)) {
                        $record->payload = [...$record->payload, 'active' => false];
                        $record->save();
                    }
                });
            } elseif ($action === 'feature') {
                DB::table('admin_console_records')->insert(['resource' => 'featured', 'created_by' => $actor->id,
                    'payload' => json_encode(['collection' => 'Discover', 'title' => $user->name, 'targetResource' => 'creators', 'targetId' => (string) $userId,
                        'position' => (int) DB::table('admin_console_records')->where('resource', 'featured')->count() + 1,
                        'priority' => 'Normal', 'startDate' => now()->toIso8601String(), 'endDate' => now()->addYears(20)->toIso8601String(), 'active' => true]),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $this->logAudit($actor, $auditAction, 'anchor_kulsan_memberships', (string) $membership->id, $this->auditValues($before), $this->auditValues($changes));
            if (in_array($action, ['revoke', 'restore'], true)) {
                $this->notify($user, 'anchor_badge_'.$action.'d', $action === 'revoke' ? 'Your Anchor Kulsan badge has been revoked.' : 'Your Anchor Kulsan badge has been restored.');
            }
            if ($action === 'activate') {
                $this->evaluateMembership((int) $membership->id, $actor);
            }
            return $this->member(DB::table('anchor_kulsan_memberships')->find($membership->id));
        });
    }

    public function evaluate(): int
    {
        $ids = DB::table('anchor_kulsan_memberships')->whereIn('status', ['selected', 'in_progress'])->orderBy('id')->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            if ($this->evaluateMembership((int) $id)) $count++;
        }
        return $count;
    }

    public function evaluateMembership(int $id, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($id, $actor): bool {
            $membership = DB::table('anchor_kulsan_memberships')->where('id', $id)->lockForUpdate()->first();
            if (! $membership || ! in_array($membership->status, ['selected', 'in_progress'], true) || ! $membership->configuration_id) return false;
            $configuration = DB::table('anchor_kulsan_configurations')->find($membership->configuration_id);
            if (! $configuration) return false;
            $milestones = json_decode($configuration->milestones, true) ?: [];
            $enabled = array_values(array_filter($milestones, fn (array $item) => $item['enabled']));
            if (! count($enabled)) return false;
            $user = User::find($membership->user_id);
            if (! $user || ! $user->roles()->where('name', 'creator')->exists() || $user->console_status !== 'Active') return false;
            $progress = [];
            foreach ($enabled as $criterion) {
                $actual = $this->metric($user, $criterion['type'], (bool) $configuration->count_existing_activity, $membership->selected_at);
                $progress[$criterion['type']] = ['actual' => $actual, 'target' => (int) $criterion['target'], 'achieved' => $actual >= (int) $criterion['target']];
            }
            $qualified = $configuration->qualification_rule === 'ANY'
                ? collect($progress)->contains(fn (array $item) => $item['achieved'])
                : collect($progress)->every(fn (array $item) => $item['achieved']);
            // Referrals are a required gate regardless of whether other milestones use ALL or ANY.
            $qualified = $qualified && ($progress['referrals']['achieved'] ?? false);
            $update = ['status' => $qualified ? 'recognized' : 'in_progress', 'qualification_snapshot' => json_encode(['configurationVersion' => $configuration->version, 'progress' => $progress]), 'updated_at' => now()];
            if ($qualified) {
                $anchorId = 'AK-'.str_pad((string) $membership->id, 3, '0', STR_PAD_LEFT);
                $update += ['anchor_id' => $anchorId, 'badge_status' => 'active', 'recognized_at' => now()];
            }
            DB::table('anchor_kulsan_memberships')->where('id', $id)->update($update);
            if ($qualified) {
                $this->logAudit($actor, 'candidate.recognized', 'anchor_kulsan_memberships', (string) $id, ['status' => $membership->status], ['status' => 'recognized', 'anchorId' => $anchorId, 'criteria' => $progress]);
                $this->notify($user, 'anchor_kulsan_recognized', 'You have met the Anchor Kulsan milestones. Your permanent ID is '.$anchorId.'.');
            }
            return $qualified;
        });
    }

    public function members(): array
    {
        $rows = DB::table('anchor_kulsan_memberships as m')->leftJoin('users as selected', 'selected.id', '=', 'm.selected_by')
            ->select('m.*', 'selected.name as selected_by_name')->orderByDesc('m.id')->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->with('onboarding')
            ->withCount(['followers', 'videos as anchor_videos_count' => fn ($query) => $query->where('status', 'ready')])
            ->withSum(['videos as anchor_views_count' => fn ($query) => $query->where('status', 'ready')], 'views_count')
            ->get()->keyBy('id');
        return $rows->map(fn ($row) => $this->member($row, $users->get($row->user_id)))->all();
    }

    public function search(string $term): array
    {
        $term = trim($term);
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', 'creator'))
            ->where('console_status', 'Active')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('anchor_kulsan_memberships')->whereColumn('anchor_kulsan_memberships.user_id', 'users.id'))
            ->where(function ($query) use ($term): void {
                $query->where('name', 'like', '%'.$term.'%')
                    ->orWhere('username', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');

                // PostgreSQL cannot compare a bigint ID to arbitrary text (such as an email).
                // Only add the ID predicate when the complete search term is numeric.
                if (ctype_digit($term)) {
                    $query->orWhere('id', $term);
                }
            })
            ->with(['onboarding'])->withCount(['followers', 'videos'])->limit(20)->get()->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name,
                'username' => $u->username, 'email' => $u->email, 'avatar' => $u->avatar, 'country' => $u->country ?? '',
                'category' => data_get($u->onboarding, 'category', 'Uncategorized'), 'accountStatus' => $u->console_status,
                'followers' => (int) $u->followers_count, 'videos' => (int) $u->videos_count])->all();
    }

    public function summary(array $members): array
    {
        $recognizedIds = collect($members)->filter(fn ($m) => $m['status'] === 'Recognized')->pluck('id');
        return ['candidates' => collect($members)->whereIn('status', ['Selected', 'In Progress'])->count(),
            'inProgress' => collect($members)->where('status', 'In Progress')->count(), 'recognized' => $recognizedIds->count(),
            'revoked' => collect($members)->where('badgeStatus', 'Revoked')->count(),
            'videos' => $recognizedIds->isEmpty() ? 0 : (int) DB::table('videos')->whereIn('user_id', $recognizedIds)->where('status', 'ready')->count(),
            'views' => $recognizedIds->isEmpty() ? 0 : (int) DB::table('videos')->whereIn('user_id', $recognizedIds)->where('status', 'ready')->sum('views_count'),
            'followers' => $recognizedIds->isEmpty() ? 0 : (int) DB::table('user_follows')->whereIn('followed_id', $recognizedIds)->count(),
            'pendingIncentives' => (int) DB::table('anchor_kulsan_benefits')->whereIn('status', ['pending', 'approved'])->count()];
    }

    public function benefits(): array
    {
        return DB::table('anchor_kulsan_benefits as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('users as c', 'c.id', '=', 'b.created_by')->leftJoin('users as r', 'r.id', '=', 'b.reviewed_by')
            ->leftJoin('users as g', 'g.id', '=', 'b.fulfilled_by')->orderByDesc('b.id')->limit(300)
            ->get(['b.*', 'u.name', 'u.username', 'u.avatar', 'c.name as creator_name', 'r.name as reviewer_name', 'g.name as fulfiller_name'])
            ->map(fn ($row) => $this->formatBenefit($row))->all();
    }

    public function createBenefit(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            $member = DB::table('anchor_kulsan_memberships')->where('user_id', $input['userId'])
                ->where('status', 'recognized')->where('badge_status', 'active')->lockForUpdate()->first();
            if (! $member) throw ValidationException::withMessages(['userId' => 'Benefits can only be assigned to an active, recognized Anchor Kulsan.']);
            $values = ['user_id' => $input['userId'], 'type' => $input['type'], 'title' => $input['title'],
                'description' => $input['description'], 'amount_ghs' => $input['type'] === 'cash' ? $input['amountGhs'] : null,
                'status' => 'pending', 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now()];
            $id = DB::table('anchor_kulsan_benefits')->insertGetId($values);
            $this->logAudit($actor, 'benefit.created', 'anchor_kulsan_benefits', (string) $id, [], $values);
            return $this->loadBenefit($id);
        });
    }

    public function benefitAction(User $actor, int $benefitId, string $action, ?string $reason, WalletService $wallets): array
    {
        return DB::transaction(function () use ($actor, $benefitId, $action, $reason, $wallets): array {
            $benefit = DB::table('anchor_kulsan_benefits')->where('id', $benefitId)->lockForUpdate()->first();
            abort_unless($benefit, 404, 'Benefit record not found.');
            $before = ['status' => $benefit->status, 'reason' => $benefit->reason, 'wallet_reference' => $benefit->wallet_reference];
            if (in_array($action, ['approve', 'reject'], true)) {
                abort_unless($benefit->status === 'pending', 409, 'Only pending benefits can be reviewed.');
                DB::table('anchor_kulsan_benefits')->where('id', $benefitId)->update([
                    'status' => $action === 'approve' ? 'approved' : 'rejected',
                    'reason' => $action === 'reject' ? $reason : null, 'reviewed_by' => $actor->id, 'updated_at' => now(),
                ]);
            } elseif ($action === 'fulfill') {
                abort_unless($benefit->type !== 'cash', 422, 'Cash benefits must be paid through the wallet grant action.');
                abort_unless($benefit->status === 'approved', 409, 'Only approved benefits can be marked fulfilled.');
                DB::table('anchor_kulsan_benefits')->where('id', $benefitId)->update([
                    'status' => 'fulfilled', 'fulfilled_by' => $actor->id, 'updated_at' => now(),
                ]);
            } elseif ($action === 'grant') {
                abort_unless($benefit->type === 'cash' && $benefit->status === 'approved', 409, 'Only approved cash benefits can be granted.');
                $creator = User::findOrFail($benefit->user_id);
                $source = $wallets->getOrCreateSystemWallet('platform', 'Kulsah Platform Wallet');
                $recipient = $wallets->getOrCreateUserWallet($creator);
                $transaction = $wallets->transferBetweenWallets(
                    $source, $recipient, $benefit->amount_ghs, 'anchor_benefit', 'Anchor cash benefit: '.$benefit->title,
                    ['anchor_benefit_id' => $benefitId, 'currency' => 'GHS'], 'available', 'available', $actor
                );
                DB::table('anchor_kulsan_benefits')->where('id', $benefitId)->update([
                    'status' => 'granted', 'fulfilled_by' => $actor->id, 'wallet_reference' => $transaction->reference, 'updated_at' => now(),
                ]);
            } else abort(404);
            $after = DB::table('anchor_kulsan_benefits')->where('id', $benefitId)->first();
            $this->logAudit($actor, 'benefit.'.$action, 'anchor_kulsan_benefits', (string) $benefitId, $before,
                ['status' => $after->status, 'reason' => $after->reason, 'wallet_reference' => $after->wallet_reference]);
            return $this->loadBenefit($benefitId);
        });
    }

    private function loadBenefit(int $id): array
    {
        $row = DB::table('anchor_kulsan_benefits as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('users as c', 'c.id', '=', 'b.created_by')->leftJoin('users as r', 'r.id', '=', 'b.reviewed_by')
            ->leftJoin('users as g', 'g.id', '=', 'b.fulfilled_by')->where('b.id', $id)
            ->first(['b.*', 'u.name', 'u.username', 'u.avatar', 'c.name as creator_name', 'r.name as reviewer_name', 'g.name as fulfiller_name']);
        return $this->formatBenefit($row);
    }

    private function formatBenefit(object $row): array
    {
        return ['id' => (string) $row->id, 'userId' => (string) $row->user_id, 'name' => $row->name,
            'username' => $row->username, 'avatar' => $row->avatar, 'type' => $row->type, 'title' => $row->title,
            'description' => $row->description, 'amountGhs' => $row->amount_ghs === null ? null : (float) $row->amount_ghs,
            'status' => $row->status, 'reason' => $row->reason, 'walletReference' => $row->wallet_reference,
            'createdBy' => $row->creator_name ?? 'System', 'reviewedBy' => $row->reviewer_name ?? null,
            'fulfilledBy' => $row->fulfiller_name ?? null, 'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at];
    }

    public function audit(): array
    {
        return DB::table('admin_console_audits as a')->leftJoin('users as u', 'u.id', '=', 'a.admin_id')
            ->whereIn('a.entity', ['anchor_kulsan_memberships', 'anchor_kulsan_configurations', 'anchor_kulsan_benefits'])->orderByDesc('a.id')->limit(100)
            ->get(['a.id', 'a.admin_id', 'u.name as admin_name', 'a.action', 'a.entity', 'a.entity_id', 'a.previous_value', 'a.new_value', 'a.created_at'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'admin' => $row->admin_name ?? 'System', 'action' => $row->action,
                'entity' => $row->entity, 'entityId' => (string) ($row->entity_id ?? ''),
                'previousValue' => json_decode($row->previous_value ?? 'null', true), 'newValue' => json_decode($row->new_value ?? 'null', true),
                'timestamp' => $row->created_at])->all();
    }

    private function metric(User $user, string $type, bool $includeExisting, string $selectedAt): int
    {
        if ($type === 'active_days') {
            // Count distinct UTC days with first-party published creator content.
            $videos = DB::table('videos')->where('user_id', $user->id)->where('status', 'ready');
            $posts = DB::table('community_posts')->where('user_id', $user->id)->where('status', 'published');
            if (! $includeExisting) {
                $videos->where('created_at', '>=', $selectedAt);
                $posts->where('created_at', '>=', $selectedAt);
            }
            return collect($videos->pluck('created_at')->merge($posts->pluck('created_at')))
                ->map(fn ($date) => substr((string) $date, 0, 10))->filter()->unique()->count();
        }
        if ($type === 'referrals') {
            $query = DB::table('anchor_referral_attributions as a')
                ->join('users as referred', 'referred.id', '=', 'a.referred_user_id')
                ->where('a.anchor_membership_id', DB::table('anchor_kulsan_memberships')->where('user_id', $user->id)->value('id'))
                ->where('referred.activated', true);
            if (! $includeExisting) $query->where('a.created_at', '>=', $selectedAt);
            return (int) $query->count();
        }
        if ($type === 'followers') {
            $query = DB::table('user_follows')->where('followed_id', $user->id);
            if (! $includeExisting) $query->where('created_at', '>=', $selectedAt);
            return (int) $query->count();
        }
        $query = DB::table('videos')->where('user_id', $user->id)->where('status', 'ready');
        if (! $includeExisting) $query->where('created_at', '>=', $selectedAt);
        return $type === 'videos' ? (int) $query->count() : (int) $query->sum('views_count');
    }

    private function member(object $row, ?User $user = null): array
    {
        $user ??= User::find($row->user_id);
        $progress = json_decode($row->qualification_snapshot ?? '', true)['progress'] ?? [];
        $status = ['selected' => 'Selected', 'in_progress' => 'In Progress', 'recognized' => 'Recognized', 'removed' => 'Removed', 'deactivated' => 'Deactivated'][$row->status] ?? 'Selected';
        return ['id' => (string) $row->user_id, 'name' => $user?->name ?? 'Deleted user', 'username' => $user?->username ?? '',
            'avatar' => $user?->avatar, 'category' => data_get($user?->onboarding, 'category', 'Uncategorized'),
            'status' => $status, 'badgeStatus' => $row->badge_status ? ucfirst($row->badge_status) : null, 'anchorId' => $row->anchor_id,
            'selectedAt' => $row->selected_at, 'selectedBy' => $row->selected_by_name ?? 'System', 'recognizedAt' => $row->recognized_at,
            'followers' => $user ? (int) ($user->followers_count ?? 0) : 0,
            'videos' => $user ? (int) ($user->anchor_videos_count ?? 0) : 0,
            'views' => $user ? (int) ($user->anchor_views_count ?? 0) : 0,
            'progress' => $progress, 'featured' => (bool) $row->featured, 'referralCode' => $row->referral_code ?? null];
    }

    private function formatConfiguration(?object $row): ?object
    {
        if (! $row) return null;
        $admin = $row->updated_by ? User::find($row->updated_by)?->name : null;
        return (object) ['version' => (int) $row->version, 'qualificationRule' => $row->qualification_rule,
            'countExistingActivity' => (bool) $row->count_existing_activity, 'active' => (bool) $row->active,
            'milestones' => json_decode($row->milestones, true) ?: [], 'updatedBy' => $admin, 'updatedAt' => $row->updated_at];
    }

    private function logAudit(?User $actor, string $action, string $entity, string $id, array $before, array $after): void
    {
        AdminConsoleAudit::query()->create(['admin_id' => $actor?->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'previous_value' => $before, 'new_value' => $after, 'ip' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 2000)]);
    }

    private function auditValues(array $values): array
    {
        return collect($values)->only([
            'status', 'status_before_deactivation', 'badge_status', 'anchor_id', 'featured',
            'removal_reason', 'deactivation_reason', 'reactivation_reason',
            'revocation_reason', 'restoration_reason',
        ])->all();
    }

    private function notify(User $user, string $type, string $message): void
    {
        try { $user->notify(new AnchorProgramNotification($type, $message)); }
        catch (\Throwable $exception) { report($exception); }
    }
}
