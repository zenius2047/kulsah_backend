<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('product_notifications')->default(true);
            $table->boolean('security_alerts')->default(true);
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_counter')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->timestamps();
        });
        Schema::create('developer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['owner_id', 'user_id']);
        });
        Schema::create('developer_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'email']);
        });
        Schema::create('developer_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_id', 80)->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('event', 100)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['owner_id', 'created_at']);
        });
        Schema::create('developer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('developer_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('developer_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('developer_webhooks')->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['developer_webhook_deliveries', 'developer_webhooks', 'developer_notifications', 'developer_request_logs', 'developer_invitations', 'developer_memberships', 'developer_preferences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
