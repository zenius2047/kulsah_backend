<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        //seed users
        DB::table('users')->insert([
            [
                'username' => 'admin',
                'email' => 'admin@kulsah.com',
                'password' => Hash::make('Admin@123'),
            ],
            [
                'username' => 'fan',
                'email' => 'fan@kulsah.com',
                'password' => Hash::make('Fan@123'),
            ],
            [
                'username' => 'creator',
                'email' => 'creator@kulsah.com',
                'password' => Hash::make('Creator@123'),
            ],
        ]);
    }
}
