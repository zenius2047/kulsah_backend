<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('reportable');
            $table->string('category');
            $table->text('reason')->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamps();

            $table->index(['reporter_id', 'status']);
            $table->index(['reportable_type', 'reportable_id', 'status'], 'signal_reports_reportable_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_reports');
    }
};
