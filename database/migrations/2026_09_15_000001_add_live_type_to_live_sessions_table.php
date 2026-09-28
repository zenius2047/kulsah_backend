<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->string('live_type', 20)->default('regular')->after('category')->index();
        });

        DB::table('live_sessions')
            ->whereIn('id', DB::table('live_battles')->select('creator_live_session_id'))
            ->orWhereIn('id', DB::table('live_battles')->select('opponent_live_session_id'))
            ->update(['live_type' => 'battle']);
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropColumn('live_type');
        });
    }
};
