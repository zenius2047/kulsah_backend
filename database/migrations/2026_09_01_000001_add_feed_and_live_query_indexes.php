<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->index(
                ['status', 'processing_status', 'visibility', 'created_at', 'id'],
                'videos_feed_ready_order_idx'
            );
            $table->index(
                ['user_id', 'status', 'processing_status', 'visibility', 'created_at', 'id'],
                'videos_creator_feed_order_idx'
            );
        });

        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->index(
                ['status', 'visibility', 'started_at', 'current_viewers'],
                'live_sessions_discovery_order_idx'
            );
        });

        Schema::table('live_viewer_sessions', function (Blueprint $table): void {
            $table->index(
                ['live_session_id', 'left_at'],
                'live_viewer_sessions_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_feed_ready_order_idx');
            $table->dropIndex('videos_creator_feed_order_idx');
        });

        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropIndex('live_sessions_discovery_order_idx');
        });

        Schema::table('live_viewer_sessions', function (Blueprint $table): void {
            $table->dropIndex('live_viewer_sessions_active_idx');
        });
    }
};