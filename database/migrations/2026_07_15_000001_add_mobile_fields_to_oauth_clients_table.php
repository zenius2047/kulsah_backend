<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->string('client_type')->default('web')->after('provider');
            $table->json('android_package_names')->nullable()->after('allowed_origins');
            $table->json('android_sha256_cert_fingerprints')->nullable()->after('android_package_names');
            $table->string('ios_bundle_id')->nullable()->after('android_sha256_cert_fingerprints');
            $table->string('ios_team_id', 20)->nullable()->after('ios_bundle_id');
            $table->json('ios_universal_link_domains')->nullable()->after('ios_team_id');
            $table->json('ios_custom_url_schemes')->nullable()->after('ios_universal_link_domains');
        });
    }

    public function down(): void
    {
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn([
                'client_type',
                'android_package_names',
                'android_sha256_cert_fingerprints',
                'ios_bundle_id',
                'ios_team_id',
                'ios_universal_link_domains',
                'ios_custom_url_schemes',
            ]);
        });
    }
};
