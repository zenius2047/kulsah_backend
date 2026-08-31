<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('average_viewers')->default(0)->after('peak_viewers');
            $table->unsignedBigInteger('watch_seconds_total')->default(0)->after('average_viewers');
            $table->unsignedBigInteger('earnings_kc')->default(0)->after('gift_value_kc');
            $table->timestamp('last_heartbeat_at')->nullable()->after('ended_at');
            $table->json('heartbeat_payload')->nullable()->after('last_heartbeat_at');
            $table->json('provider_metadata')->nullable()->after('heartbeat_payload');
        });

        Schema::create('live_cohost_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_session_id')->constrained('live_sessions')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 24)->index();
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['live_session_id', 'status']);
            $table->index(['invitee_id', 'status']);
            $table->unique(['live_session_id', 'requester_id', 'invitee_id'], 'live_cohost_requests_unique_request');
        });

        Schema::create('live_battles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('creator_live_session_id')->constrained('live_sessions')->cascadeOnDelete();
            $table->foreignId('opponent_live_session_id')->constrained('live_sessions')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opponent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('creator_score')->default(0);
            $table->unsignedBigInteger('opponent_score')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['creator_live_session_id', 'status']);
            $table->index(['opponent_live_session_id', 'status']);
            $table->index(['creator_id', 'opponent_id']);
        });

        Schema::create('live_battle_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_battle_id')->constrained('live_battles')->cascadeOnDelete();
            $table->foreignId('live_session_id')->constrained('live_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('side', 24);
            $table->unsignedBigInteger('score')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['live_battle_id', 'user_id']);
            $table->index(['live_battle_id', 'side']);
        });

        Schema::create('live_recordings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_session_id')->constrained('live_sessions')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_resource_id')->nullable();
            $table->string('provider_sid')->nullable();
            $table->string('status', 24)->index();
            $table->string('storage_disk')->nullable();
            $table->text('storage_path')->nullable();
            $table->string('replay_state', 24)->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['live_session_id', 'status']);
            $table->index(['provider', 'provider_sid']);
        });

        Schema::create('live_analytics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_session_id')->constrained('live_sessions')->cascadeOnDelete()->unique();
            $table->unsignedBigInteger('unique_viewers')->default(0);
            $table->unsignedBigInteger('peak_viewers')->default(0);
            $table->unsignedBigInteger('average_viewers')->default(0);
            $table->unsignedBigInteger('watch_seconds')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('gifts_count')->default(0);
            $table->unsignedBigInteger('gross_gift_value_kc')->default(0);
            $table->unsignedBigInteger('creator_earnings_kc')->default(0);
            $table->unsignedBigInteger('platform_revenue_kc')->default(0);
            $table->unsignedBigInteger('new_fans_count')->default(0);
            $table->unsignedBigInteger('new_subscribers_count')->default(0);
            $table->unsignedBigInteger('cohost_requests_count')->default(0);
            $table->unsignedBigInteger('successful_cohosts_count')->default(0);
            $table->unsignedBigInteger('reports_count')->default(0);
            $table->unsignedBigInteger('mutes_count')->default(0);
            $table->unsignedBigInteger('removals_count')->default(0);
            $table->unsignedBigInteger('disconnects_count')->default(0);
            $table->unsignedBigInteger('reconnects_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['live_analytics', 'live_recordings', 'live_battle_participants', 'live_battles', 'live_cohost_requests'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'average_viewers',
                'watch_seconds_total',
                'earnings_kc',
                'last_heartbeat_at',
                'heartbeat_payload',
                'provider_metadata',
            ]);
        });
    }
};
