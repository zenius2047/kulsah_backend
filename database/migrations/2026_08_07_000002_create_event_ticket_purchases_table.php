<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ticket_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticket_type_code', 80);
            $table->string('ticket_type_name');
            $table->json('ticket_type_snapshot');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 4);
            $table->decimal('total_amount', 12, 4);
            $table->string('currency', 10);
            $table->string('status', 20)->default('completed');
            $table->string('reference')->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestampTz('purchased_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'buyer_id']);
            $table->index(['buyer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_ticket_purchases');
    }
};
