<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('language', 20)->nullable()->index();
            $table->string('country_code', 10)->nullable();
            $table->enum('owner_type', ['kulsah', 'user', 'creator', 'brand'])->default('kulsah');
            $table->boolean('is_official')->default(false);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('use_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'is_public', 'is_featured']);
        });

        Schema::create('stickers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sticker_pack_id')->constrained('sticker_packs')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('type', ['static', 'animated_webp', 'gif', 'video'])->default('static');
            $table->string('media_url');
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('is_animated')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->enum('visibility', ['private', 'public', 'official'])->default('public');
            $table->enum('moderation_status', ['pending', 'approved', 'rejected', 'hidden', 'removed'])->default('approved');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('usage_count')->default(0);
            $table->unsignedBigInteger('favorite_count')->default(0);
            $table->json('tags')->nullable();
            $table->string('language', 20)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['sticker_pack_id', 'is_active']);
            $table->index(['visibility', 'moderation_status', 'is_active']);
        });

        Schema::create('sticker_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sticker_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'sticker_id']);
        });

        Schema::create('sticker_recents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sticker_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamp('last_used_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'sticker_id']);
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->foreignId('sticker_id')->nullable()->after('type')->constrained('stickers')->nullOnDelete();
        });
        Schema::table('video_comments', function (Blueprint $table): void {
            $table->foreignId('sticker_id')->nullable()->after('body')->constrained('stickers')->nullOnDelete();
        });
        Schema::table('community_post_comments', function (Blueprint $table): void {
            $table->foreignId('sticker_id')->nullable()->after('body')->constrained('stickers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('community_post_comments', fn (Blueprint $table) => $table->dropForeign(['sticker_id']));
        Schema::table('community_post_comments', fn (Blueprint $table) => $table->dropColumn('sticker_id'));
        Schema::table('video_comments', fn (Blueprint $table) => $table->dropForeign(['sticker_id']));
        Schema::table('video_comments', fn (Blueprint $table) => $table->dropColumn('sticker_id'));
        Schema::table('conversation_messages', fn (Blueprint $table) => $table->dropForeign(['sticker_id']));
        Schema::table('conversation_messages', fn (Blueprint $table) => $table->dropColumn('sticker_id'));
        Schema::dropIfExists('sticker_recents');
        Schema::dropIfExists('sticker_favorites');
        Schema::dropIfExists('stickers');
        Schema::dropIfExists('sticker_packs');
    }
};


