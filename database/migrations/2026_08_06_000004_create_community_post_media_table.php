<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->string('media_type', 20);
            $table->string('disk', 50);
            $table->string('source_key');
            $table->text('source_url');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('cloudinary_public_id')->nullable();
            $table->string('cloudinary_asset_id')->nullable();
            $table->text('cloudinary_url')->nullable();
            $table->text('cloudinary_stream_url')->nullable();
            $table->text('cloudinary_thumbnail_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['community_post_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_post_media');
    }
};
