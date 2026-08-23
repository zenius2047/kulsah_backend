<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_battle_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_winner_id')->constrained('challenge_winners')->restrictOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedBigInteger('vote_count')->default(0);
            $table->unsignedBigInteger('vote_coin_amount')->default(0);
            $table->decimal('conversion_rate', 18, 6)->default(0);
            $table->decimal('usd_amount', 18, 4)->default(0);
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('challenge_id');
            $table->unique('challenge_winner_id');
            $table->unique('challenge_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_battle_settlements');
    }
};