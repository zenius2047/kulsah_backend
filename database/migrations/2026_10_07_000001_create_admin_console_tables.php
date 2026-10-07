<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('console_status')->default('Active')->index();
            $table->string('console_verification')->nullable();
            $table->string('admin_console_role')->nullable();
            $table->boolean('admin_console_disabled')->default(false);
            $table->json('console_preferences')->nullable();
        });
        Schema::create('admin_console_records', function (Blueprint $table) {
            $table->id();
            $table->string('resource')->index();
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('admin_console_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity');
            $table->string('entity_id')->nullable();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_console_audits');
        Schema::dropIfExists('admin_console_records');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'console_status', 'console_verification', 'admin_console_role',
            'admin_console_disabled', 'console_preferences',
        ]));
    }
};
