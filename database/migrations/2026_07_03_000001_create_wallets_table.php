<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_key')->nullable()->unique();
            $table->string('account_name');
            $table->string('base_currency', 10)->default('USD');
            $table->decimal('available_balance_usd', 18, 4)->default(0);
            $table->decimal('pending_balance_usd', 18, 4)->default(0);
            $table->decimal('held_balance_usd', 18, 4)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('last_ledger_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['status', 'base_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
