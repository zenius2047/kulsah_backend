<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $t->string('title', 160); $t->text('description')->nullable(); $t->string('category', 100)->nullable(); $t->string('cover_url')->nullable();
            $t->string('visibility', 20)->default('public'); $t->timestamp('scheduled_at')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('ended_at')->nullable();
            $t->string('status', 24)->index(); $t->string('provider', 32); $t->string('provider_channel', 100)->unique();
            $t->boolean('chat_enabled')->default(true); $t->boolean('gifts_enabled')->default(true); $t->boolean('recording_enabled')->default(false);
            $t->unsignedInteger('current_viewers')->default(0); $t->unsignedInteger('unique_viewers')->default(0); $t->unsignedInteger('peak_viewers')->default(0);
            $t->unsignedBigInteger('likes_count')->default(0); $t->unsignedInteger('comments_count')->default(0); $t->unsignedInteger('gifts_count')->default(0); $t->unsignedBigInteger('gift_value_kc')->default(0);
            $t->string('termination_reason')->nullable(); $t->string('recording_state')->nullable(); $t->string('replay_state')->nullable(); $t->timestamps();
            $t->index(['status', 'visibility']); $t->index(['creator_id', 'status']);
        });
        Schema::create('live_provider_identities', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('provider', 32); $t->unsignedBigInteger('provider_uid'); $t->timestamps(); $t->unique(['provider', 'user_id']); $t->unique(['provider', 'provider_uid']); });
        Schema::create('live_viewer_sessions', function (Blueprint $t) { $t->id(); $t->foreignId('live_session_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('session_key', 100); $t->timestamp('joined_at'); $t->timestamp('left_at')->nullable(); $t->unsignedInteger('watch_seconds')->default(0); $t->timestamp('last_heartbeat_at')->nullable(); $t->timestamps(); $t->unique('session_key'); $t->index(['live_session_id', 'user_id']); });
        Schema::create('live_comments', function (Blueprint $t) { $t->id(); $t->foreignId('live_session_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->text('body'); $t->timestamp('deleted_at')->nullable(); $t->timestamps(); $t->index(['live_session_id', 'created_at']); });
        Schema::create('live_cohosts', function (Blueprint $t) { $t->id(); $t->foreignId('live_session_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('status', 20); $t->timestamp('accepted_at')->nullable(); $t->timestamp('removed_at')->nullable(); $t->timestamps(); $t->index(['live_session_id', 'status']); });
        Schema::create('live_moderators', function (Blueprint $t) { $t->id(); $t->foreignId('live_session_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->foreignId('appointed_by')->constrained('users'); $t->timestamp('removed_at')->nullable(); $t->timestamps(); $t->unique(['live_session_id', 'user_id']); });
        Schema::create('live_moderation_actions', function (Blueprint $t) { $t->id(); $t->foreignId('live_session_id')->constrained()->cascadeOnDelete(); $t->foreignId('actor_id')->constrained('users'); $t->foreignId('target_id')->constrained('users'); $t->string('action', 32); $t->string('reason')->nullable(); $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); $t->index(['live_session_id', 'target_id', 'action']); });
    }
    public function down(): void { foreach (['live_moderation_actions','live_moderators','live_cohosts','live_comments','live_viewer_sessions','live_provider_identities','live_sessions'] as $table) Schema::dropIfExists($table); }
};
