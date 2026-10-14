<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anchor_kulsan_configurations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('qualification_rule', 3);
            $table->boolean('count_existing_activity')->default(true);
            $table->json('milestones');
            $table->boolean('active')->default(false)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('anchor_kulsan_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('configuration_id')->nullable()->constrained('anchor_kulsan_configurations')->nullOnDelete();
            $table->string('status', 20)->default('selected')->index();
            $table->string('anchor_id', 40)->nullable()->unique();
            $table->string('badge_status', 20)->nullable()->index();
            $table->timestamp('selected_at');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('restoration_reason')->nullable();
            $table->boolean('featured')->default(false)->index();
            $table->json('qualification_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anchor_kulsan_memberships');
        Schema::dropIfExists('anchor_kulsan_configurations');
    }
};
