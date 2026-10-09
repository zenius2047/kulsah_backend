<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_boost_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('package_name');
            $table->decimal('budget_amount', 12, 2);
            $table->string('currency', 10);
            $table->unsignedBigInteger('estimated_reach')->default(1);
            $table->json('targeting')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable()->index();
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('spent_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('engagements_count')->default(0);
            $table->text('rejection_reason')->nullable();
            $table->text('pause_reason')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['video_id', 'status']);
            $table->index(['creator_id', 'created_at']);
        });

        Schema::create('video_boost_impressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('video_boost_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('viewer_id')->nullable()->index();
            $table->timestamp('served_at')->useCurrent()->index();
            $table->index(['campaign_id', 'viewer_id', 'served_at'], 'boost_impressions_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_boost_impressions');
        Schema::dropIfExists('video_boost_campaigns');
    }
};