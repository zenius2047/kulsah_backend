<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_viewer_states', function (Blueprint $table) {
            $table->id();
            $table->string('viewer_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_key', 128)->nullable()->index();
            $table->json('seen_video_ids')->nullable();
            $table->json('interest_terms')->nullable();
            $table->json('creator_affinity')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
            $table->index(['device_key', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_viewer_states');
    }
};