<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kulcoin_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kulcoin_transaction_id')->constrained('kulcoin_transactions')->cascadeOnDelete();
            $table->foreignId('kulcoin_wallet_id')->constrained('kulcoin_wallets')->cascadeOnDelete();
            $table->string('entry_type');
            $table->string('balance_bucket');
            $table->unsignedBigInteger('amount_kc');
            $table->unsignedBigInteger('running_balance_kc')->nullable();
            $table->text('narration')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('settlement_available_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['kulcoin_wallet_id', 'balance_bucket']);
            $table->index(['kulcoin_transaction_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kulcoin_ledger_entries');
    }
};
