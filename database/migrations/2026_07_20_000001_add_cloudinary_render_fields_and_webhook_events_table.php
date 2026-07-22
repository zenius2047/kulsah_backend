<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('rendered_url')->nullable()->after('cdn_url');
            $table->string('streaming_url')->nullable()->after('rendered_url');
            $table->string('poster_url')->nullable()->after('thumbnail_url');
            $table->string('render_status')->nullable()->index()->after('status');
            $table->string('cloudinary_asset_id')->nullable()->index()->after('cloudinary_public_id');
            $table->string('cloudinary_render_id')->nullable()->index()->after('cloudinary_asset_id');
            $table->timestampTz('render_completed_at')->nullable()->after('cloudinary_render_id');
        });

        Schema::create('cloudinary_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudinary_webhook_events');

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'rendered_url',
                'streaming_url',
                'poster_url',
                'render_status',
                'cloudinary_asset_id',
                'cloudinary_render_id',
                'render_completed_at',
            ]);
        });
    }
};
