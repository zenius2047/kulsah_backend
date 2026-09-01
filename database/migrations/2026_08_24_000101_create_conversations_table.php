<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('conversation_key')->unique();
            $table->string('context_type')->nullable();
            $table->unsignedBigInteger('context_id')->nullable()->index();
            $table->unsignedBigInteger('last_message_id')->nullable()->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->boolean('is_group')->default(false);
            $table->timestamps();

            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

