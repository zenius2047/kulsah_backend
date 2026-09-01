<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->boolean('allow_duet')->default(false)->after('visibility')->index();
            $table->foreignId('duet_source_video_id')
                ->nullable()
                ->after('user_id')
                ->constrained('videos', 'id', 'videos_duet_source_video_id_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropForeign('videos_duet_source_video_id_foreign');
            $table->dropColumn('duet_source_video_id');
            $table->dropColumn('allow_duet');
        });
    }
};
