<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('provider');
            $table->text('description')->nullable()->after('logo');
            $table->json('allowed_origins')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn(['logo', 'description', 'allowed_origins']);
        });
    }
};
