<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kulcoin_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('type');
            $table->string('status')->default('completed');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counterparty_wallet_id')->nullable()->constrained('kulcoin_wallets')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('kulcoin_packages')->nullOnDelete();
            $table->foreignId('gift_id')->nullable()->constrained('kulcoin_gifts')->nullOnDelete();
            $table->string('local_currency', 10)->nullable();
            $table->decimal('local_amount', 18, 4)->nullable();
            $table->decimal('usd_amount', 18, 4)->default(0);
            $table->unsignedBigInteger('coin_amount')->default(0);
            $table->unsignedBigInteger('bonus_coin_amount')->default(0);
            $table->unsignedBigInteger('net_coin_amount')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kulcoin_transactions');
    }
};
