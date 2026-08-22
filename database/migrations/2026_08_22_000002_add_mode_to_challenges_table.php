<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->string('mode', 32)->default('open')->after('visibility')->index();
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropIndex(['mode']);
            $table->dropColumn('mode');
        });
    }
};
