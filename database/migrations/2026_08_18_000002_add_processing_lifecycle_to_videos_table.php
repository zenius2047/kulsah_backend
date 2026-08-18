<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('media_type', 24)->default('video')->after('user_id');
            $table->string('purpose', 48)->default('post_video')->index()->after('media_type');
            $table->string('original_filename')->nullable()->after('content_types');
            $table->string('mime_type', 120)->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->string('source_disk', 64)->nullable()->after('file_size');
            $table->string('source_bucket')->nullable()->after('source_disk');
            $table->string('upload_status', 32)->default('initialized')->index()->after('source_url');
            $table->string('processing_status', 32)->default('initialized')->index()->after('upload_status');
            $table->string('playback_type', 24)->nullable()->after('streaming_url');
            $table->text('hls_url')->nullable()->after('playback_type');
            $table->text('dash_url')->nullable()->after('hls_url');
            $table->text('fallback_mp4_url')->nullable()->after('dash_url');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('duration');
            $table->unsignedInteger('width')->nullable()->after('duration_ms');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('aspect_ratio', 24)->nullable()->after('height');
            $table->decimal('fps', 8, 3)->nullable()->after('aspect_ratio');
            $table->text('processing_error')->nullable()->after('render_status');
            $table->timestampTz('uploaded_at')->nullable()->after('processing_error');
            $table->timestampTz('processing_started_at')->nullable()->after('uploaded_at');
            $table->timestampTz('processed_at')->nullable()->after('processing_started_at');
            $table->timestampTz('failed_at')->nullable()->after('processed_at');

            $table->index(['user_id', 'upload_status']);
            $table->index(['processing_status', 'created_at']);
        });

        DB::table('videos')->where('status', 'ready')->update([
            'upload_status' => 'uploaded',
            'processing_status' => 'ready',
            'playback_type' => 'hls',
            'hls_url' => DB::raw('streaming_url'),
            'uploaded_at' => DB::raw('updated_at'),
            'processed_at' => DB::raw('COALESCE(render_completed_at, updated_at)'),
        ]);
        DB::table('videos')->where('status', 'processing')->update([
            'upload_status' => 'uploaded',
            'processing_status' => 'processing',
            'uploaded_at' => DB::raw('updated_at'),
        ]);
        DB::table('videos')->where('status', 'failed')->update([
            'upload_status' => 'uploaded',
            'processing_status' => 'processing_failed',
            'failed_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'upload_status']);
            $table->dropIndex(['processing_status', 'created_at']);
            $table->dropColumn([
                'media_type', 'purpose', 'original_filename', 'mime_type', 'file_size',
                'source_disk', 'source_bucket', 'upload_status', 'processing_status',
                'playback_type', 'hls_url', 'dash_url', 'fallback_mp4_url', 'duration_ms',
                'width', 'height', 'aspect_ratio', 'fps', 'processing_error', 'uploaded_at',
                'processing_started_at', 'processed_at', 'failed_at',
            ]);
        });
    }
};
