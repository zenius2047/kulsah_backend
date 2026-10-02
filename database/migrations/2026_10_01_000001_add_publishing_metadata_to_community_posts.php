<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->string('location_name')->nullable()->after('audience');
            $table->json('hashtags')->nullable()->after('poll');
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('published_at')->nullable()->after('scheduled_at');
            $table->index(['status', 'scheduled_at'], 'community_posts_schedule_index');
        });

        Schema::create('community_post_user_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['community_post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_post_user_tags');

        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropIndex('community_posts_schedule_index');
            $table->dropColumn(['location_name', 'hashtags', 'scheduled_at', 'published_at']);
        });
    }
};
