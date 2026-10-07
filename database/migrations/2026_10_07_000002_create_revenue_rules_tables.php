<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_key')->index();
            $table->json('scope')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('revenue_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revenue_rule_id')->constrained('revenue_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('deduction_type');
            $table->decimal('value', 14, 4);
            $table->string('currency', 12);
            $table->string('payer');
            $table->string('recipient');
            $table->string('remaining_recipient');
            $table->decimal('minimum', 14, 4)->nullable();
            $table->decimal('maximum', 14, 4)->nullable();
            $table->timestamp('effective_at');
            $table->string('status');
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_modified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['revenue_rule_id', 'version']);
            $table->index(['effective_at', 'status']);
        });
        Schema::create('revenue_rule_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('revenue_rule_version_id')->constrained('revenue_rule_versions')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['revenue_rule_version_id', 'created_at']);
            $table->unique('revenue_rule_version_id');
        });
        Schema::create('revenue_rule_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('revenue_rule_id')->constrained('revenue_rules')->cascadeOnDelete();
            $table->foreignUuid('revenue_rule_version_id')->nullable()->constrained('revenue_rule_versions')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('snapshot')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['revenue_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_rule_audits');
        Schema::dropIfExists('revenue_rule_reviews');
        Schema::dropIfExists('revenue_rule_versions');
        Schema::dropIfExists('revenue_rules');
    }
};
