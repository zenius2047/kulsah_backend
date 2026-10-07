<?php

namespace App\Services;

use App\Models\User;

class AdminConsoleAccess
{
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.edit', 'users.suspend', 'users.delete',
        'creators.view', 'creators.verify', 'creators.suspend', 'content.view',
        'content.approve', 'content.edit', 'content.remove', 'moderation.view',
        'moderation.resolve', 'events.view', 'events.approve', 'events.edit',
        'tickets.view', 'tickets.refund', 'subscriptions.view', 'finance.view',
        'finance.payout', 'finance.adjust', 'kulcoin.view', 'kulcoin.manage',
        'kulcoin.adjust', 'gifts.view', 'gifts.manage', 'analytics.view',
        'notifications.view', 'notifications.send', 'cms.view', 'cms.edit',
        'audit.view', 'settings.view', 'settings.edit', 'admins.view', 'admins.manage',
        'finance.rules.manage', 'finance.rules.approve',
    ];

    public const ROLES = ['Super Admin', 'Operations Manager', 'Moderator', 'Finance Officer', 'Support Agent'];

    public function role(User $user): string
    {
        // Only the existing server-side admin role admits staff to the console.
        abort_unless($user->roles()->where('name', 'admin')->exists(), 403, 'Administrator access is required.');
        abort_if($user->admin_console_disabled || $user->console_status !== 'Active', 403, 'This administrator account is disabled.');
        $role = $user->admin_console_role ?: 'Super Admin';
        abort_unless(in_array($role, self::ROLES, true), 403, 'No console role is assigned.');
        return $role;
    }

    public function permissions(string $role): array
    {
        if ($role !== 'Super Admin') {
            $saved = \App\Models\AdminConsoleRecord::where('resource', 'roles')->where('payload->name', $role)->first();
            if ($saved) return array_values(array_intersect($saved->payload['permissions'] ?? [], self::PERMISSIONS));
        }
        $allowed = match ($role) {
            'Super Admin' => self::PERMISSIONS,
            'Operations Manager' => array_diff(self::PERMISSIONS, ['admins.manage', 'settings.edit', 'finance.payout', 'finance.adjust', 'finance.rules.manage', 'finance.rules.approve', 'kulcoin.adjust']),
            'Moderator' => ['users.view', 'users.suspend', 'creators.view', 'creators.suspend', 'content.view', 'content.approve', 'content.remove', 'moderation.view', 'moderation.resolve', 'analytics.view'],
            'Finance Officer' => ['users.view', 'creators.view', 'finance.view', 'finance.payout', 'finance.adjust', 'finance.rules.manage', 'finance.rules.approve', 'kulcoin.view', 'kulcoin.manage', 'kulcoin.adjust', 'gifts.view', 'gifts.manage', 'analytics.view', 'audit.view'],
            'Support Agent' => ['users.view', 'creators.view', 'content.view', 'events.view', 'tickets.view', 'subscriptions.view', 'moderation.view', 'finance.view', 'kulcoin.view', 'gifts.view', 'analytics.view'],
            default => [],
        };
        return array_values($allowed);
    }

    public function authorize(User $user, string $permission): void
    {
        abort_unless(in_array($permission, $this->permissions($this->role($user)), true), 403, 'You do not have permission for this action.');
    }

    public function session(User $user): array
    {
        $role = $this->role($user);
        return ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar' => $user->avatar,
            'role' => $role, 'permissions' => $this->permissions($role), 'twoFactorEnabled' => false];
    }
}
