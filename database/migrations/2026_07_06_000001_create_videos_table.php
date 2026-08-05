<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_key')->index();
            $table->string('cdn_url')->nullable();
            $table->string('cloudinary_public_id')->nullable()->index();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('status')->default('processing')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
