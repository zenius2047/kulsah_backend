<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('provider_reference', 120)->nullable()->unique();
            $table->string('provider_transaction_id', 120)->nullable()->unique();
            $table->string('provider', 40)->default('paystack');
            $table->string('purpose', 60);
            $table->nullableMorphs('payable');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 10);
            $table->string('status', 30)->default('pending');
            $table->string('provider_status', 60)->nullable();
            $table->string('channel', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
            $table->index(['purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
