<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anchor_kulsan_benefits', function (Blueprint $table): void {
            $table->decimal('amount_ghs', 14, 4)->nullable()->after('description');
            $table->string('wallet_reference')->nullable()->unique()->after('fulfilled_by');
        });
    }

    public function down(): void
    {
        Schema::table('anchor_kulsan_benefits', function (Blueprint $table): void {
            $table->dropUnique(['wallet_reference']);
            $table->dropColumn(['amount_ghs', 'wallet_reference']);
        });
    }
};
