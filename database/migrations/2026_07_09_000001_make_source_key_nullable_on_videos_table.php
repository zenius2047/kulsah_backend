<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE videos ALTER COLUMN source_key DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE videos ALTER COLUMN source_key SET NOT NULL');
    }
};
