<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('applicant_type', 24)->default('individual');
            $table->char('country_code', 2)->index();
            $table->string('status', 32)->index();
            $table->timestamp('submitted_at')->index();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('applicant_data');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_status', 24)->default('pending');
            $table->longText('provider_result')->nullable();
            $table->string('document_authenticity_status', 24)->default('pending');
            $table->string('face_match_status', 24)->default('pending');
            $table->string('liveness_status', 24)->default('pending');
            $table->string('payout_ownership_status', 24)->default('pending');
            $table->date('document_expires_at')->nullable();
            $table->text('creator_message')->nullable();
            $table->unsignedInteger('review_version')->default(0);
            $table->timestamps();
            $table->index(['status', 'country_code', 'submitted_at']);
            $table->index(['assigned_reviewer_id', 'status']);
        });

        Schema::create('kyc_application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('kyc_applications')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('capture', 40)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('private_path', 500);
            $table->unsignedSmallInteger('submission_version')->default(1);
            $table->timestamps();
            $table->index(['application_id', 'submission_version']);
        });

        Schema::create('kyc_application_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('kyc_applications')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('kyc_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('kyc_applications')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('creator_message')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('kyc_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60);
            $table->string('provider_event_id', 160);
            $table->foreignId('application_id')->nullable()->constrained('kyc_applications')->nullOnDelete();
            $table->string('outcome', 24);
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_provider_events');
        Schema::dropIfExists('kyc_application_events');
        Schema::dropIfExists('kyc_application_notes');
        Schema::dropIfExists('kyc_application_documents');
        Schema::dropIfExists('kyc_applications');
    }
};
