<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->string('signal_message_policy')->default('people_i_may_know')->after('marketing');
            $table->boolean('signal_allow_contact_sync')->default(true)->after('signal_message_policy');
            $table->boolean('signal_discoverable_by_phone')->default(true)->after('signal_allow_contact_sync');
            $table->boolean('signal_discoverable_by_search')->default(true)->after('signal_discoverable_by_phone');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn([
                'signal_message_policy',
                'signal_allow_contact_sync',
                'signal_discoverable_by_phone',
                'signal_discoverable_by_search',
            ]);
        });
    }
};
