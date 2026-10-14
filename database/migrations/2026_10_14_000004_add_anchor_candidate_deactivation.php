<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anchor_kulsan_memberships', function (Blueprint $table): void {
            $table->string('status_before_deactivation', 20)->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deactivation_reason')->nullable();
            $table->timestamp('reactivated_at')->nullable();
            $table->foreignId('reactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reactivation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('anchor_kulsan_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropConstrainedForeignId('reactivated_by');
            $table->dropColumn([
                'status_before_deactivation', 'deactivated_at', 'deactivation_reason',
                'reactivated_at', 'reactivation_reason',
            ]);
        });
    }
};
