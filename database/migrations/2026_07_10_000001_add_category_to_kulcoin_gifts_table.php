<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kulcoin_gifts ADD COLUMN category VARCHAR(120) NULL');
        DB::statement('CREATE INDEX kulcoin_gifts_category_index ON kulcoin_gifts (category)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kulcoin_gifts_category_index');
        DB::statement('ALTER TABLE kulcoin_gifts DROP COLUMN category');
    }
};
