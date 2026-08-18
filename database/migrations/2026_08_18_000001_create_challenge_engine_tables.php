<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('host_type', 24);
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('host_organization_id')->nullable()->index();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->text('instructions')->nullable();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('hashtag')->nullable()->index();
            $table->string('visibility', 32)->default('public')->index();
            $table->string('status', 32)->default('draft')->index();
            $table->string('judging_strategy', 32)->default('points');
            $table->string('winner_selection_method', 32)->default('automatic_score');
            $table->timestamp('registration_starts_at')->nullable();
            $table->timestamp('registration_ends_at')->nullable();
            $table->timestamp('submission_starts_at');
            $table->timestamp('submission_ends_at');
            $table->timestamp('voting_starts_at')->nullable();
            $table->timestamp('voting_ends_at')->nullable();
            $table->timestamp('judging_starts_at')->nullable();
            $table->timestamp('judging_ends_at')->nullable();
            $table->timestamp('results_publish_at')->nullable();
            $table->boolean('show_leaderboard')->default(true);
            $table->string('leaderboard_mode', 24)->default('live');
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedSmallInteger('max_entries_per_creator')->default(1);
            $table->foreignId('official_sound_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->string('moderation_status', 32)->nullable()->index();
            $table->unsignedInteger('rules_version')->default(1);
            $table->json('voting_configuration')->nullable();
            $table->json('integrity_configuration')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'submission_starts_at']);
            $table->index(['status', 'submission_ends_at']);
            $table->index(['status', 'voting_ends_at']);
        });

        Schema::create('challenge_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        Schema::create('challenge_sponsors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('sponsor_name');
            $table->string('sponsor_type', 32);
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('challenge_reward_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_id')->nullable()->constrained('challenge_sponsors')->nullOnDelete();
            $table->string('funding_source_type', 32);
            $table->unsignedBigInteger('funding_source_id')->nullable();
            $table->string('currency', 10);
            $table->decimal('committed_amount', 18, 4)->default(0);
            $table->decimal('funded_amount', 18, 4)->default(0);
            $table->decimal('reserved_amount', 18, 4)->default(0);
            $table->decimal('distributed_amount', 18, 4)->default(0);
            $table->string('status', 32)->default('pledged')->index();
            $table->timestamp('funded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('challenge_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank_from');
            $table->unsignedSmallInteger('rank_to');
            $table->string('reward_type', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->foreignId('reward_pool_id')->nullable()->constrained('challenge_reward_pools')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['challenge_id', 'rank_from', 'rank_to']);
        });

        Schema::create('challenge_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 24);
            $table->string('rule_type', 64);
            $table->string('operator', 16)->default('=');
            $table->json('value');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('rules_version')->default(1);
            $table->timestamps();
            $table->index(['challenge_id', 'scope', 'rules_version']);
        });

        Schema::create('challenge_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->restrictOnDelete();
            $table->string('role', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'video_id', 'role']);
        });

        Schema::create('challenge_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'invited_user_id']);
        });

        Schema::create('challenge_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('video_id')->constrained('videos')->restrictOnDelete();
            $table->unsignedSmallInteger('submission_number');
            $table->text('caption')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->string('moderation_status', 32)->nullable()->index();
            $table->string('eligibility_status', 32)->default('eligible')->index();
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('disqualified_at')->nullable();
            $table->text('disqualification_reason')->nullable();
            $table->decimal('current_score', 20, 6)->default(0);
            $table->unsignedInteger('current_rank')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'video_id']);
            $table->unique(['challenge_id', 'creator_id', 'submission_number'], 'challenge_creator_submission_unique');
            $table->index(['challenge_id', 'status']);
            $table->index(['challenge_id', 'current_score']);
        });

        Schema::create('challenge_entry_eligibility_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->unsignedInteger('rules_version');
            $table->boolean('eligible');
            $table->json('evaluation');
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });

        Schema::create('challenge_judging_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->string('stage_type', 32);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'sequence']);
        });

        Schema::create('challenge_scoring_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedSmallInteger('weight_bps')->default(0);
            $table->decimal('point_value', 12, 4)->nullable();
            $table->string('normalization_method', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('configuration')->nullable();
            $table->unsignedInteger('rules_version')->default(1);
            $table->timestamps();
            $table->unique(['challenge_id', 'type', 'rules_version']);
        });

        Schema::create('challenge_jury_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('judge');
            $table->unsignedSmallInteger('weight_bps')->default(10000);
            $table->string('status', 24)->default('pending');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        Schema::create('challenge_jury_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('min_score', 10, 2)->default(0);
            $table->decimal('max_score', 10, 2)->default(100);
            $table->unsignedSmallInteger('weight_bps');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('challenge_jury_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->foreignId('jury_member_id')->constrained('challenge_jury_members')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('challenge_jury_criteria')->cascadeOnDelete();
            $table->decimal('score', 10, 2);
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['jury_member_id', 'challenge_entry_id', 'criterion_id'], 'jury_entry_criterion_unique');
        });

        Schema::create('challenge_ballots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 24)->default('submitted');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['challenge_id', 'voter_id']);
        });

        Schema::create('challenge_ballot_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ballot_id')->constrained('challenge_ballots')->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->decimal('points', 12, 4)->nullable();
            $table->timestamps();
            $table->unique(['ballot_id', 'challenge_entry_id']);
            $table->unique(['ballot_id', 'rank']);
        });

        Schema::create('challenge_entry_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->foreignId('scoring_component_id')->constrained('challenge_scoring_components')->cascadeOnDelete();
            $table->decimal('raw_value', 20, 6)->default(0);
            $table->decimal('normalized_value', 12, 6)->default(0);
            $table->decimal('weighted_value', 20, 6)->default(0);
            $table->timestamp('calculated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['challenge_entry_id', 'scoring_component_id']);
        });

        Schema::create('challenge_score_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->decimal('final_score', 20, 6);
            $table->unsignedInteger('rank')->nullable();
            $table->json('components');
            $table->string('reason', 32)->default('recalculation');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['challenge_id', 'captured_at']);
        });

        Schema::create('challenge_entry_advancements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('challenge_judging_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('challenge_judging_stages')->nullOnDelete();
            $table->string('status', 24)->default('advanced');
            $table->text('reason')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('challenge_integrity_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->nullable()->constrained('challenge_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('severity', 24);
            $table->string('status', 24)->default('open');
            $table->json('evidence')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['challenge_id', 'status']);
        });

        Schema::create('challenge_selection_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->cascadeOnDelete();
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->unsignedInteger('rank')->nullable();
            $table->text('reason');
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('challenge_winners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_entry_id')->constrained('challenge_entries')->restrictOnDelete();
            $table->unsignedInteger('rank');
            $table->string('status', 24)->default('pending');
            $table->decimal('final_score', 20, 6);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by_winner_id')->nullable()->constrained('challenge_winners')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'rank']);
            $table->unique(['challenge_id', 'challenge_entry_id']);
        });

        Schema::create('challenge_reward_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('winner_id')->constrained('challenge_winners')->restrictOnDelete();
            $table->foreignId('prize_id')->constrained('challenge_prizes')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 10)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('allocated_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['winner_id', 'prize_id']);
        });

        Schema::create('challenge_reward_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->constrained('challenge_reward_allocations')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->string('provider', 32)->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('challenge_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['challenge_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (array_reverse([
            'challenges', 'challenge_collaborators', 'challenge_sponsors', 'challenge_reward_pools',
            'challenge_prizes', 'challenge_rules', 'challenge_media', 'challenge_invites', 'challenge_entries',
            'challenge_entry_eligibility_snapshots', 'challenge_judging_stages', 'challenge_scoring_components',
            'challenge_jury_members', 'challenge_jury_criteria', 'challenge_jury_scores', 'challenge_ballots',
            'challenge_ballot_choices', 'challenge_entry_scores', 'challenge_score_snapshots',
            'challenge_entry_advancements', 'challenge_integrity_flags', 'challenge_selection_decisions',
            'challenge_winners', 'challenge_reward_allocations', 'challenge_reward_transactions',
            'challenge_audit_logs',
        ]) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
