<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->timestamp('live_started_notifications_sent_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropColumn('live_started_notifications_sent_at');
        });
    }
};