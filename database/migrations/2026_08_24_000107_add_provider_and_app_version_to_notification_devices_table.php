<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_devices', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('token');
            $table->string('app_version', 64)->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('notification_devices', function (Blueprint $table) {
            $table->dropColumn(['provider', 'app_version']);
        });
    }
};
