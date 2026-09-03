<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('base_currency', 10)->default('GHS')->change();
        });

        // Existing balances are platform balances and are now denominated in GHS.
        DB::table('wallets')->update(['base_currency' => 'GHS']);
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('base_currency', 10)->default('USD')->change();
        });

        DB::table('wallets')->update(['base_currency' => 'USD']);
    }
};