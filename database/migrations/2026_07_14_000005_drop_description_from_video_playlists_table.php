<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('video_playlists', 'description')) {
            Schema::table('video_playlists', function (Blueprint $table): void {
                $table->dropColumn('description');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('video_playlists', 'description')) {
            Schema::table('video_playlists', function (Blueprint $table): void {
                $table->text('description')->nullable()->after('name');
            });
        }
    }
};
