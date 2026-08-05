<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos')) {
            DB::statement('ALTER TABLE videos ALTER COLUMN source_url TYPE TEXT');
            DB::statement('ALTER TABLE videos ALTER COLUMN cdn_url TYPE TEXT');
            DB::statement('ALTER TABLE videos ALTER COLUMN rendered_url TYPE TEXT');
            DB::statement('ALTER TABLE videos ALTER COLUMN streaming_url TYPE TEXT');
            DB::statement('ALTER TABLE videos ALTER COLUMN poster_url TYPE TEXT');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('videos')) {
            DB::statement('ALTER TABLE videos ALTER COLUMN source_url TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE videos ALTER COLUMN cdn_url TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE videos ALTER COLUMN rendered_url TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE videos ALTER COLUMN streaming_url TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE videos ALTER COLUMN poster_url TYPE VARCHAR(255)');
        }
    }
};
