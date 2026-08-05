<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('type');
            $table->string('status')->default('completed');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counterparty_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('local_currency', 10)->nullable();
            $table->decimal('local_amount', 18, 4)->nullable();
            $table->decimal('usd_amount', 18, 4)->default(0);
            $table->decimal('fx_rate_used', 18, 6)->nullable();
            $table->decimal('platform_fee_usd', 18, 4)->default(0);
            $table->decimal('processor_fee_usd', 18, 4)->default(0);
            $table->decimal('net_usd_amount', 18, 4)->default(0);
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
        Schema::dropIfExists('wallet_transactions');
    }
};
