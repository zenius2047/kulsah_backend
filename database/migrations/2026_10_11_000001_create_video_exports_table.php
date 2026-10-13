<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_exports', function (Blueprint $table): void {
            $table->id();
            // Retain orphan records until private objects have been cleaned up.
            $table->unsignedBigInteger('video_id')->unique();
            $table->string('fingerprint', 64);
            $table->uuid('token');
            $table->string('status')->default('pending');
            $table->string('disk');
            $table->string('path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_exports');
    }
};
