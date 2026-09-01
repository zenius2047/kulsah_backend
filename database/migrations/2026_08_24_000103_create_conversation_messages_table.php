<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_message_id')->nullable()->index();
            $table->string('idempotency_key')->nullable();
            $table->string('type')->default('text');
            $table->longText('body')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('delivery_status')->default('sent');
            $table->timestamp('edited_at')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['conversation_id', 'sender_id', 'idempotency_key']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
