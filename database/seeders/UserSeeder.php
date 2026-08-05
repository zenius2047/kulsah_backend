<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $roles = Role::query()
                ->whereIn('name', ['admin', 'fan', 'creator'])
                ->pluck('id', 'name');

            $users = [
                [
                    'name' => 'System Admin',
                    'username' => 'admin',
                    'email' => 'admin@kulsah.com',
                    'password' => 'Admin@123',
                    'role' => 'admin',
                ],
                [
                    'name' => 'Test Fan',
                    'username' => 'fan',
                    'email' => 'fan@kulsah.com',
                    'password' => 'Fan@123',
                    'role' => 'fan',
                ],
                [
                    'name' => 'Test Creator',
                    'username' => 'creator',
                    'email' => 'creator@kulsah.com',
                    'password' => 'Creator@123',
                    'role' => 'creator',
                ],
                 [
                    'name' => 'Test Fan',
                    'username' => 'fans',
                    'email' => 'fans@kulsah.com',
                    'password' => 'Fans@123',
                    'role' => 'fan',
                ],
            ];

            foreach ($users as $userData) {
                $user = User::query()->updateOrCreate(
                    [
                        'username' => $userData['username'],
                    ],
                    [
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                        'password' => Hash::make($userData['password']),
                        'activated' => true,
                        'activated_at' => now(),
                        'verified' => false,
                        'verified_at' => now(),
                    ]
                );

                if (isset($roles[$userData['role']])) {
                    $user->roles()->syncWithoutDetaching([$roles[$userData['role']]]);
                }
            }
        });
    }
}
