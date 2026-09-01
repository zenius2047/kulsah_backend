<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_post_comments')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['community_post_id', 'parent_id']);
        });

        Schema::create('community_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['community_post_id', 'user_id']);
        });

        Schema::create('community_post_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['community_post_id', 'user_id']);
        });

        Schema::create('community_post_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gift_id')->constrained('kulcoin_gifts')->cascadeOnDelete();
            $table->foreignId('kulcoin_transaction_id')->nullable()->constrained('kulcoin_transactions')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('coin_amount')->default(0);
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['community_post_id', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_post_gifts');
        Schema::dropIfExists('community_post_shares');
        Schema::dropIfExists('community_post_likes');
        Schema::dropIfExists('community_post_comments');
    }
};
