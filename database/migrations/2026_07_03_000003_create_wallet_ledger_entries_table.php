<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('entry_type');
            $table->string('balance_bucket');
            $table->decimal('amount_usd', 18, 4);
            $table->decimal('running_balance_usd', 18, 4)->nullable();
            $table->text('narration')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('settlement_available_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'balance_bucket']);
            $table->index(['wallet_transaction_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
