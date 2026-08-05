<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_comment_id')->constrained('video_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['video_comment_id', 'user_id'], 'video_comment_likes_unique_comment_user');
            $table->index(['user_id', 'video_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_comment_likes');
    }
};
