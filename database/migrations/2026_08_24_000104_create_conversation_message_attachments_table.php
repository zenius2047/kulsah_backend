<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind')->default('file');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('disk');
            $table->string('source_key');
            $table->text('source_url')->nullable();
            $table->string('status')->default('initialized');
            $table->timestamp('uploaded_at')->nullable()->index();
            $table->timestamp('attached_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_attachments');
    }
};
