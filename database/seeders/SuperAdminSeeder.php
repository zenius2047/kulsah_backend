<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('admin-console.super_admin.email')));
        $password = (string) config('admin-console.super_admin.password');
        $name = trim((string) config('admin-console.super_admin.name', 'Kulsah Super Admin'));
        $username = trim((string) config('admin-console.super_admin.username'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Set a valid SUPER_ADMIN_EMAIL in the Laravel .env file.');
        }

        if (strlen($password) < 12) {
            throw new InvalidArgumentException('Set SUPER_ADMIN_PASSWORD to a unique password with at least 12 characters.');
        }

        if (! Schema::hasColumn('users', 'admin_console_role')) {
            throw new RuntimeException('Run php artisan migrate before seeding the admin console account.');
        }

        $username = $username !== '' ? $username : Str::slug(Str::before($email, '@'), '.');
        $username = $username !== '' ? $username : 'super-admin';
        $name = $name !== '' ? $name : 'Kulsah Super Admin';

        DB::transaction(function () use ($email, $password, $name, $username): void {
            $user = User::query()->firstOrNew(['email' => $email]);
            $usernameOwner = User::query()->where('username', $username)->first();

            if ($usernameOwner && $user->exists && $usernameOwner->getKey() !== $user->getKey()) {
                throw new RuntimeException("SUPER_ADMIN_USERNAME '{$username}' is already used by another account.");
            }

            if ($usernameOwner && ! $user->exists) {
                throw new RuntimeException("SUPER_ADMIN_USERNAME '{$username}' is already used. Set a different SUPER_ADMIN_USERNAME.");
            }

            if (! $user->exists) {
                $user->forceFill([
                    'username' => $username,
                    'name' => $name,
                ]);
            } elseif (! $user->username) {
                $user->username = $username;
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'activated' => true,
                'activated_at' => $user->activated_at ?: now(),
                'verified' => true,
                'verified_at' => $user->verified_at ?: now(),
                'console_status' => 'Active',
                'admin_console_role' => 'Super Admin',
                'admin_console_disabled' => false,
            ])->save();

            $adminRole = Role::query()->firstOrCreate(['name' => 'admin']);
            $user->roles()->syncWithoutDetaching([$adminRole->getKey()]);
        });

        $this->command?->info("Super admin account is ready for {$email}. The password was not printed.");
    }
}