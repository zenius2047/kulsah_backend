<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('idempotency_key', 120)->nullable();
                $table->unique(['user_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique('payments_user_id_idempotency_key_unique');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
