<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('videos', 'video_playlist_id')) {
            Schema::table('videos', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('video_playlist_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->foreignId('video_playlist_id')
                ->nullable()
                ->constrained('video_playlists')
                ->nullOnDelete();
        });
    }
};
