<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kulcoin_gifts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('coin_cost');
            $table->boolean('is_active')->default(true);
            $table->string('icon_url')->nullable();
            $table->string('animation_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'coin_cost']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kulcoin_gifts');
    }
};
