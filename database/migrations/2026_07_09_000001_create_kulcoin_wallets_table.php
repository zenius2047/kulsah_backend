<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kulcoin_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_key')->nullable()->unique();
            $table->string('account_name');
            $table->string('currency_code', 10)->default('KC');
            $table->unsignedBigInteger('available_balance_kc')->default(0);
            $table->unsignedBigInteger('bonus_balance_kc')->default(0);
            $table->string('status')->default('active');
            $table->timestamp('last_ledger_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['status', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kulcoin_wallets');
    }
};
