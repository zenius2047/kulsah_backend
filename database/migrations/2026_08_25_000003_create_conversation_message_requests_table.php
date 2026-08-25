<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('intro_client_message_id')->nullable();
            $table->string('intro_type')->default('text');
            $table->longText('intro_body')->nullable();
            $table->json('intro_metadata')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('cooldown_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['sender_id', 'receiver_id'], 'conversation_message_requests_unique_sender_receiver');
            $table->index(['receiver_id', 'status']);
            $table->index(['sender_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_requests');
    }
};
