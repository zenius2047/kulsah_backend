<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->string('status', 20)->default('published')->after('audience');
            $table->unsignedBigInteger('views_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropColumn(['status', 'views_count']);
        });
    }
};
