<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('bookmarks_count')->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE videos
            SET likes_count = (SELECT COUNT(*) FROM video_likes WHERE video_likes.video_id = videos.id),
                comments_count = (SELECT COUNT(*) FROM video_comments WHERE video_comments.video_id = videos.id AND video_comments.deleted_at IS NULL),
                bookmarks_count = (SELECT COUNT(*) FROM video_bookmarks WHERE video_bookmarks.video_id = videos.id)
            SQL);

        Schema::table('videos', function (Blueprint $table): void {
            $table->index(
                ['status', 'processing_status', 'visibility', 'views_count', 'likes_count', 'created_at', 'id'],
                'videos_feed_trending_order_idx'
            );
        });

        Schema::table('video_views', function (Blueprint $table): void {
            $table->index(['user_id', 'viewed_at', 'video_id'], 'video_views_user_recent_idx');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->index(['subscriber_id', 'status', 'creator_id'], 'subscriptions_subscriber_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_subscriber_status_idx');
        });

        Schema::table('video_views', function (Blueprint $table): void {
            $table->dropIndex('video_views_user_recent_idx');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_feed_trending_order_idx');
            $table->dropColumn(['likes_count', 'comments_count', 'bookmarks_count']);
        });
    }
};
