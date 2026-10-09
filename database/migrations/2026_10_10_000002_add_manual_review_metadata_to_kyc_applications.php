<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_applications', function (Blueprint $table) {
            $table->string('review_method', 24)->nullable()->after('assigned_reviewer_id');
            $table->foreignId('reviewed_by')->nullable()->after('review_method')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_method', 'reviewed_at', 'review_reason']);
        });
    }
};
