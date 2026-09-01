<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->timestampTz('last_activity_at')->nullable()->after('views_count');
            $table->index(['status', 'audience', 'last_activity_at'], 'community_posts_feed_activity_index');
        });

        Schema::create('community_post_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->timestampTz('first_viewed_at');
            $table->timestampTz('last_viewed_at');
            $table->unsignedBigInteger('view_count')->default(0);
            $table->decimal('watch_duration_seconds', 12, 3)->default(0);
            $table->decimal('last_watch_duration_seconds', 12, 3)->default(0);
            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->decimal('max_completion_percentage', 5, 2)->default(0);
            $table->unsignedBigInteger('completed_count')->default(0);
            $table->boolean('reached_25_percent')->default(false);
            $table->boolean('reached_50_percent')->default(false);
            $table->boolean('reached_75_percent')->default(false);
            $table->boolean('reached_90_percent')->default(false);
            $table->boolean('engaged')->default(false);
            $table->timestampTz('last_engaged_at')->nullable();
            $table->timestampTz('last_counted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'community_post_id'], 'community_post_views_user_post_unique');
            $table->index(['user_id', 'last_viewed_at'], 'community_post_views_user_last_index');
            $table->index(['community_post_id', 'last_viewed_at'], 'community_post_views_post_last_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_post_views');

        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropIndex('community_posts_feed_activity_index');
            $table->dropColumn('last_activity_at');
        });
    }
};
