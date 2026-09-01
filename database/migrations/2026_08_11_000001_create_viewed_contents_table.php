<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewed_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('viewable_type', 60);
            $table->unsignedBigInteger('viewable_id');
            $table->timestamp('viewed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['viewer_id', 'viewable_type', 'viewable_id'], 'viewed_contents_unique');
            $table->index(['viewer_id', 'viewable_type'], 'viewed_contents_viewer_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewed_contents');
    }
};
