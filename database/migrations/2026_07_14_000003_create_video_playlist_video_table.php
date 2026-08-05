<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_playlist_video', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_playlist_id')->constrained('video_playlists')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['video_playlist_id', 'video_id'], 'video_playlist_video_unique');
            $table->index(['video_id', 'video_playlist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_playlist_video');
    }
};
