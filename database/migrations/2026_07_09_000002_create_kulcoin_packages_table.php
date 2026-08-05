<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kulcoin_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('coin_amount');
            $table->unsignedInteger('bonus_coin_amount')->default(0);
            $table->decimal('usd_price', 12, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['coin_amount', 'usd_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kulcoin_packages');
    }
};
