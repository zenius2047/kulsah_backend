<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //seed roles
        DB::table('roles')->upsert([
            ['name' => 'admin'],
            ['name' => 'guest'],
            ['name' => 'fan'],
            ['name' => 'creator'],
        ], ['name'], ['name']);
    }
}
