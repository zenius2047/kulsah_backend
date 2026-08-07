<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_purchase_id')->constrained('event_ticket_purchases')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticket_id')->unique();
            $table->unsignedInteger('ticket_number');
            $table->string('scan_signature', 255);
            $table->text('verification_url');
            $table->text('qr_code_url');
            $table->string('status', 20)->default('active');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'buyer_id']);
            $table->index(['event_ticket_purchase_id', 'ticket_number']);
            $table->index(['status', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
    }
};
