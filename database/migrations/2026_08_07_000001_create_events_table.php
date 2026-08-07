<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 120);
            $table->string('venue_type', 20);
            $table->string('venue_name')->nullable();
            $table->text('venue_address')->nullable();
            $table->text('meeting_url')->nullable();
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('timezone', 100);
            $table->unsignedInteger('capacity');
            $table->string('currency', 10);
            $table->string('cover_image_disk')->nullable();
            $table->string('cover_image_key')->nullable();
            $table->text('cover_image_url')->nullable();
            $table->json('ticket_types');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('tickets_sold')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'starts_at']);
            $table->index(['venue_type', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
