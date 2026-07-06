<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_ledger_entries', 'settlement_available_at')) {
                $table->timestamp('settlement_available_at')->nullable()->after('metadata');
            }

            if (! Schema::hasColumn('wallet_ledger_entries', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('settlement_available_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_ledger_entries', 'settled_at')) {
                $table->dropColumn('settled_at');
            }

            if (Schema::hasColumn('wallet_ledger_entries', 'settlement_available_at')) {
                $table->dropColumn('settlement_available_at');
            }
        });
    }
};
