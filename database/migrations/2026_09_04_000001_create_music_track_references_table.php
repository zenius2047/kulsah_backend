<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_track_references', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_id', 191);
            $table->string('normalized_id')->unique();
            $table->string('title_snapshot')->nullable();
            $table->string('artist_snapshot')->nullable();
            $table->string('artist_id_snapshot')->nullable();
            $table->string('artist_username_snapshot')->nullable();
            $table->json('artwork_snapshot')->nullable();
            $table->unsignedInteger('duration_snapshot')->nullable();
            $table->string('source_permalink')->nullable();
            $table->string('source_url')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_track_references');
    }
};
