<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kulcoin_gifts ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
        DB::statement('CREATE INDEX kulcoin_gifts_is_active_sort_order_index ON kulcoin_gifts (is_active, sort_order)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kulcoin_gifts_is_active_sort_order_index');
        DB::statement('ALTER TABLE kulcoin_gifts DROP COLUMN sort_order');
    }
};
