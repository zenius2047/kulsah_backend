<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->boolean('notify_followers')->default(true);
            $table->boolean('age_restricted')->default(false);
            $table->string('stream_quality', 20)->default('1080p_30fps');
            $table->string('orientation', 20)->default('portrait');
            $table->json('moderation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'notify_followers',
                'age_restricted',
                'stream_quality',
                'orientation',
                'moderation',
            ]);
        });
    }
};
